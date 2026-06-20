<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Infrastructure\Rabbit\RabbitWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Rabbit\SendWelcomeEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublishFailedException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * RabbitWelcomeEmailRelay publishes on a DEDICATED confirm-mode channel opened off
 * the shared RabbitConnection (H1: never the worker's long-lived consume channel).
 * The mocked consume channel yields a mocked publish channel via
 * getConnection()->channel() — the same pattern as RabbitConsumerTest — so the
 * broker ack/nack/timeout is simulated without a live broker.
 *
 * AC (D1): a confirmed publish lands a SendWelcomeEmail/v1 with correlation_id =
 * sagaId, delivery_mode 2, and returns only on broker ack; an unconfirmed publish
 * throws so the caller does NOT advance the saga. The dedicated channel is always
 * closed (H1: confirm mode never leaks onto the consume channel).
 */
final class RabbitWelcomeEmailRelayTest extends TestCase
{
    public function testPublishesToTheWelcomeExchangeAndRoutingKey(): void
    {
        $captured = [];
        $publishChannel = $this->ackingPublishChannel($captured);

        $this->relayWith($publishChannel)->publish($this->message());

        self::assertSame('notifications', $captured[0]['exchange']);
        self::assertSame('subscription.welcome-email', $captured[0]['routingKey']);
    }

    public function testPublishesPersistentJsonWithCorrelationIdEqualToSagaId(): void
    {
        $captured = [];
        $publishChannel = $this->ackingPublishChannel($captured);

        $this->relayWith($publishChannel)->publish($this->message());

        self::assertSame('application/json', $captured[0]['properties']['content_type']);
        self::assertSame(2, $captured[0]['properties']['delivery_mode']);
        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $captured[0]['properties']['correlation_id']);
    }

    public function testPublishesTheSerializedBody(): void
    {
        $message = $this->message();
        $expectedBody = (new SendWelcomeEmailSerializer())->toJson($message);

        $captured = [];
        $publishChannel = $this->ackingPublishChannel($captured);

        $this->relayWith($publishChannel)->publish($message);

        self::assertSame($expectedBody, $captured[0]['body']);
    }

    public function testPublishesOnADedicatedChannelAndClosesItLeavingTheConsumeChannelUntouched(): void
    {
        $captured = [];
        $publishChannel = $this->ackingPublishChannel($captured);
        $publishChannel->expects(self::once())->method('confirm_select');
        $publishChannel->expects(self::once())->method('close');

        // The shared (consume) channel must NEVER be flipped into confirm mode.
        $consumeChannel = $this->consumeChannelYielding($publishChannel);
        $consumeChannel->expects(self::never())->method('confirm_select');

        $relay = new RabbitWelcomeEmailRelay(
            new RabbitConnection($consumeChannel),
            new SendWelcomeEmailSerializer(),
            new NullLogger(),
        );

        $relay->publish($this->message());
    }

    public function testLogsOnceAfterAConfirmedPublish(): void
    {
        $captured = [];
        $publishChannel = $this->ackingPublishChannel($captured);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('welcome email command published', self::callback(
                static fn(array $ctx): bool =>
                    $ctx['saga_id'] === '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33'
                    && $ctx['subscription_id'] === 123
                    && $ctx['repository'] === 'owner/repo'
            ));

        $this->relayWith($publishChannel, $logger)->publish($this->message());
    }

    public function testThrowsAndDoesNotLogWhenTheBrokerNacksSoTheSagaIsNotAdvanced(): void
    {
        $publishChannel = $this->nackingPublishChannel();
        $publishChannel->expects(self::once())->method('close');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $this->expectException(RabbitPublishFailedException::class);

        $this->relayWith($publishChannel, $logger)->publish($this->message());
    }

    /** @return AMQPChannel&MockObject */
    private function publishChannelBase(): AMQPChannel&MockObject
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('confirm_select')->willReturn(null);
        $channel->method('close')->willReturn(null);

        return $channel;
    }

    /**
     * The dedicated publish channel on the happy path: confirm-wait invokes no nack
     * handler, so the publish is treated as confirmed. Captures published copies.
     *
     * @param list<array{
     *     exchange: string,
     *     routingKey: string,
     *     body: string,
     *     properties: array<string, mixed>
     * }> $captured
     * @return AMQPChannel&MockObject
     */
    private function ackingPublishChannel(array &$captured): AMQPChannel&MockObject
    {
        $channel = $this->publishChannelBase();
        $channel->method('set_nack_handler')->willReturnCallback(static fn() => null);
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $msg, string $ex = '', string $rk = '') use (&$captured): void {
                $captured[] = [
                    'exchange' => $ex,
                    'routingKey' => $rk,
                    'body' => $msg->getBody(),
                    'properties' => $msg->get_properties(),
                ];
            }
        );
        $channel->method('wait_for_pending_acks')->willReturn(null);

        return $channel;
    }

    /**
     * The dedicated publish channel on the nack path: the registered nack handler is
     * invoked during wait_for_pending_acks (php-amqplib calls it rather than throwing).
     *
     * @return AMQPChannel&MockObject
     */
    private function nackingPublishChannel(): AMQPChannel&MockObject
    {
        $channel = $this->publishChannelBase();

        $nackHandler = null;
        $channel->method('set_nack_handler')->willReturnCallback(
            function (callable $cb) use (&$nackHandler): void {
                $nackHandler = $cb;
            }
        );
        $channel->method('basic_publish')->willReturn(null);
        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$nackHandler): void {
                self::assertNotNull($nackHandler);
                ($nackHandler)();
            }
        );

        return $channel;
    }

    /**
     * A consume channel (RabbitConnection's shared channel) whose getConnection()
     * yields a connection that hands out the given dedicated publish channel.
     *
     * @return AMQPChannel&MockObject
     */
    private function consumeChannelYielding(AMQPChannel $publishChannel): AMQPChannel&MockObject
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        $connection = $this->createMock(AbstractConnection::class);
        $connection->method('channel')->willReturn($publishChannel);
        $channel->method('getConnection')->willReturn($connection);

        return $channel;
    }

    private function relayWith(AMQPChannel $publishChannel, ?LoggerInterface $logger = null): RabbitWelcomeEmailRelay
    {
        return new RabbitWelcomeEmailRelay(
            new RabbitConnection($this->consumeChannelYielding($publishChannel)),
            new SendWelcomeEmailSerializer(),
            $logger ?? new NullLogger(),
        );
    }

    private function message(): SendWelcomeEmail
    {
        return new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new \DateTimeImmutable('2026-06-20T12:00:00+00:00'),
        );
    }
}
