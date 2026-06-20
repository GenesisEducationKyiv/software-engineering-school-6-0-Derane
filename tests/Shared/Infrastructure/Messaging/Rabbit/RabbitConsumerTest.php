<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RetryPublishFailedException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Monolith RabbitConsumer is now the verbatim copy of the live notification
 * consumer (M4 fence): bounded prefetch, `x-retry-count`-header retry/park on a
 * DEDICATED confirm-mode channel, fail-closed (no-ack) on an unconfirmed copy, and
 * the `>=` DLQ bound. The mechanics are exercised against a mocked AMQPChannel
 * (RabbitConsumer/RabbitConnection are final readonly and cannot be doubled).
 */
final class RabbitConsumerTest extends TestCase
{
    private const RETRY_QUEUE = 'notifications.welcome-email.retry';
    private const VALID_JSON = '{"schema":"WelcomeEmailOutcome/v1"}';
    private const CLAIM_LEASE_SECONDS = 300;

    public function testConsumeSetsBoundedPrefetchThenStartsConsuming(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_qos')->with(0, 10, false);
        $channel->expects(self::once())
            ->method('basic_consume')
            ->with('the-queue', '', false, false, false, false, self::anything());

        $this->consumer($channel)->consume('the-queue', static fn(AMQPMessage $m): null => null);
    }

    public function testRequeueWithRetryRepublishesADelayedCopyPreservingPropertiesAndIncrementingTheCount(): void
    {
        $published = [];
        $acked = [];
        $channel = $this->channel($acked, $this->publishChannel($published));
        $message = $this->message($channel, deliveryTag: 7, retryCount: 1);

        $this->consumer($channel)->requeueWithRetry($message, self::RETRY_QUEUE);

        self::assertCount(1, $published);
        // Default exchange + queue-name routing key = direct to the retry parking queue.
        self::assertSame('', $published[0]['exchange']);
        self::assertSame(self::RETRY_QUEUE, $published[0]['routingKey']);

        $copy = $published[0]['message'];
        self::assertSame(self::VALID_JSON, $copy->getBody());
        self::assertSame('application/json', $copy->get('content_type'));
        self::assertSame(2, $copy->get('delivery_mode'));
        // newCount = 2 → delay 5s * 2^(2-1) = 10s, in milliseconds.
        self::assertSame('10000', $copy->get('expiration'));

        $headers = $copy->get('application_headers');
        self::assertInstanceOf(AMQPTable::class, $headers);
        self::assertSame(2, $headers->getNativeData()['x-retry-count']);

        // Original acked only after the copy is confirmed.
        self::assertSame(7, $acked['ack'] ?? null);
    }

    public function testRequeueWithoutRetryIncrementParksForTheGivenDelayPreservingTheRetryCount(): void
    {
        $published = [];
        $acked = [];
        $channel = $this->channel($acked, $this->publishChannel($published));
        $message = $this->message($channel, deliveryTag: 8, retryCount: 2);

        $this->consumer($channel)->requeueWithoutRetryIncrement(
            $message,
            self::RETRY_QUEUE,
            self::CLAIM_LEASE_SECONDS,
        );

        self::assertCount(1, $published);
        $copy = $published[0]['message'];
        self::assertSame((string) (self::CLAIM_LEASE_SECONDS * 1000), $copy->get('expiration'));

        $headers = $copy->get('application_headers');
        self::assertInstanceOf(AMQPTable::class, $headers);
        self::assertSame(2, $headers->getNativeData()['x-retry-count']);

        self::assertSame(8, $acked['ack'] ?? null);
    }

    public function testRepublishFailsClosedAndDoesNotAckWhenTheConfirmWaitTimesOut(): void
    {
        $acked = [];
        $publish = $this->createMock(AMQPChannel::class);
        $publish->method('confirm_select')->willReturn(null);
        $publish->method('set_nack_handler')->willReturn(null);
        $publish->method('basic_publish')->willReturn(null);
        $publish->method('wait_for_pending_acks')->willReturnCallback(static function (): void {
            throw new AMQPTimeoutException('confirm timed out');
        });
        $publish->method('close')->willReturn(null);

        $channel = $this->channel($acked, $publish);
        $message = $this->message($channel, deliveryTag: 9, retryCount: 0);

        try {
            $this->consumer($channel)->requeueWithRetry($message, self::RETRY_QUEUE);
            self::fail('expected RetryPublishFailedException');
        } catch (RetryPublishFailedException) {
            // expected
        }

        // Fail closed: the original is NOT acked, so the broker redelivers it.
        self::assertArrayNotHasKey('ack', $acked);
    }

    public function testRepublishFailsClosedAndDoesNotAckWhenTheBrokerNacksTheCopy(): void
    {
        $acked = [];
        $nackHandler = null;
        $publish = $this->createMock(AMQPChannel::class);
        $publish->method('confirm_select')->willReturn(null);
        $publish->method('set_nack_handler')->willReturnCallback(
            function (callable $handler) use (&$nackHandler): void {
                $nackHandler = $handler;
            }
        );
        $publish->method('basic_publish')->willReturn(null);
        // wait_for_pending_acks invokes the registered nack handler on a broker nack
        // rather than throwing; the production handler then throws.
        $publish->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$nackHandler): void {
                self::assertIsCallable($nackHandler, 'nack handler must be registered before the confirm-wait');
                ($nackHandler)();
            }
        );
        $publish->method('close')->willReturn(null);

        $channel = $this->channel($acked, $publish);
        $message = $this->message($channel, deliveryTag: 10, retryCount: 0);

        try {
            $this->consumer($channel)->requeueWithRetry($message, self::RETRY_QUEUE);
            self::fail('expected RetryPublishFailedException');
        } catch (RetryPublishFailedException) {
            // expected
        }

        self::assertArrayNotHasKey('ack', $acked);
    }

    public function testShouldRouteToDlqIsTrueAtOrAboveTheBoundAndFalseBelow(): void
    {
        $channel = $this->channel();
        $consumer = $this->consumer($channel);

        self::assertFalse($consumer->shouldRouteToDlq($this->message($channel, 1, 2), 3));
        self::assertTrue($consumer->shouldRouteToDlq($this->message($channel, 2, 3), 3));
        self::assertTrue($consumer->shouldRouteToDlq($this->message($channel, 3, 5), 3));
    }

    public function testNackWithoutRequeueDelegatesToTheMessageForDeadLettering(): void
    {
        $acked = [];
        $channel = $this->channel($acked);
        $message = $this->message($channel, deliveryTag: 11, retryCount: 0);

        $this->consumer($channel)->nack($message, requeue: false);

        self::assertSame(['nack' => [11, false]], $acked);
    }

    public function testAckDelegatesToTheMessage(): void
    {
        $acked = [];
        $channel = $this->channel($acked);
        $message = $this->message($channel, deliveryTag: 12, retryCount: 0);

        $this->consumer($channel)->ack($message);

        self::assertSame(12, $acked['ack'] ?? null);
    }

    private function consumer(AMQPChannel $channel): RabbitConsumer
    {
        return new RabbitConsumer($this->connectionWrapping($channel));
    }

    private function connectionWrapping(AMQPChannel $channel): RabbitConnection
    {
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        return new RabbitConnection($channel);
    }

    /**
     * The consume channel: topology stubs + ack/nack capture, wired so
     * getConnection()->channel() yields the dedicated publish channel.
     *
     * @param array<string, mixed> $captured by reference
     * @return AMQPChannel&MockObject
     */
    private function channel(array &$captured = [], ?AMQPChannel $publishChannel = null): AMQPChannel&MockObject
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);
        $channel->method('basic_ack')->willReturnCallback(
            function (int $deliveryTag) use (&$captured): void {
                $captured['ack'] = $deliveryTag;
            }
        );
        $channel->method('basic_nack')->willReturnCallback(
            function (int $deliveryTag, bool $multiple, bool $requeue) use (&$captured): void {
                $captured = ['nack' => [$deliveryTag, $requeue]];
            }
        );

        $connection = $this->createMock(AbstractConnection::class);
        $connection->method('channel')->willReturn($publishChannel ?? $this->publishChannel());
        $channel->method('getConnection')->willReturn($connection);

        return $channel;
    }

    /**
     * The dedicated publish channel used for the retry/park copy (happy path:
     * confirm-wait succeeds). Captures published copies for assertions.
     *
     * @param list<array{message: AMQPMessage, exchange: string, routingKey: string}> $published by reference
     * @return AMQPChannel&MockObject
     */
    private function publishChannel(array &$published = []): AMQPChannel&MockObject
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('confirm_select')->willReturn(null);
        $channel->method('set_nack_handler')->willReturn(null);
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message, string $exchange = '', string $routingKey = '') use (&$published): void {
                $published[] = ['message' => $message, 'exchange' => $exchange, 'routingKey' => $routingKey];
            }
        );
        $channel->method('wait_for_pending_acks')->willReturn(null);
        $channel->method('close')->willReturn(null);

        return $channel;
    }

    private function message(AMQPChannel $channel, int $deliveryTag, int $retryCount): AMQPMessage
    {
        $message = new AMQPMessage(self::VALID_JSON, [
            'content_type' => 'application/json',
            'application_headers' => new AMQPTable(['x-retry-count' => $retryCount]),
        ]);
        $message->setChannel($channel)
            ->setDeliveryInfo($deliveryTag, true, 'notifications', 'subscription.welcome-email.reply');

        return $message;
    }
}
