<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\RabbitReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublishFailedException;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests RabbitReleaseNotificationPublisher by wiring it with a real (mocked-channel)
 * RabbitPublisher and the real SendReleaseEmailSerializer — both are final readonly
 * classes and cannot be doubled by PHPUnit. The underlying AMQPChannel is mocked to
 * simulate broker ack/nack responses without a live broker (same pattern as
 * RabbitPublisherTest).
 */
final class RabbitReleaseNotificationPublisherTest extends TestCase
{
    public function testPublishesWithCorrectExchangeAndRoutingKey(): void
    {
        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publishAll([$this->makeMessage()]);

        self::assertSame('notifications', $captured[0]['exchange']);
        self::assertSame('release.email', $captured[0]['routingKey']);
    }

    public function testPublishesWithPersistentDeliveryMode(): void
    {
        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publishAll([$this->makeMessage()]);

        self::assertSame(2, $captured[0]['properties']['delivery_mode']);
    }

    public function testPublishesWithApplicationJsonContentType(): void
    {
        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publishAll([$this->makeMessage()]);

        self::assertSame('application/json', $captured[0]['properties']['content_type']);
    }

    public function testPublishesSerializedJsonBody(): void
    {
        $message = $this->makeMessage();
        $expectedBody = (new SendReleaseEmailSerializer())->toJson($message);

        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publishAll([$message]);

        self::assertSame($expectedBody, $captured[0]['body']);
    }

    public function testBatchPublishesOneSerializedMessagePerRecipientWithASingleConfirmWait(): void
    {
        $messages = [$this->makeMessage(1), $this->makeMessage(2), $this->makeMessage(3)];

        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured, expectedConfirmWaits: 1);

        $this->adapterWith($channel)->publishAll($messages);

        self::assertCount(3, $captured);
        $serializer = new SendReleaseEmailSerializer();
        foreach ($messages as $i => $message) {
            self::assertSame('notifications', $captured[$i]['exchange']);
            self::assertSame('release.email', $captured[$i]['routingKey']);
            self::assertSame($serializer->toJson($message), $captured[$i]['body']);
        }
    }

    public function testLogsOneEventIdCorrelatedLinePerPublishedRecipient(): void
    {
        $messages = [$this->makeMessage(1), $this->makeMessage(2)];

        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        /** @var list<array{message: string, context: array<string, mixed>}> $logged */
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))
            ->method('info')
            ->willReturnCallback(
                function (string|\Stringable $message, array $context) use (&$logged): void {
                    $logged[] = ['message' => (string) $message, 'context' => $context];
                }
            );

        $this->adapterWith($channel, $logger)->publishAll($messages);

        self::assertCount(2, $logged);
        foreach ($messages as $i => $message) {
            self::assertSame('release email published', $logged[$i]['message']);
            self::assertSame($message->eventId, $logged[$i]['context']['event_id']);
            self::assertSame($message->subscriptionId, $logged[$i]['context']['subscription_id']);
            self::assertSame('owner/repo', $logged[$i]['context']['repository']);
            self::assertSame('v1.2.3', $logged[$i]['context']['tag']);
        }
    }

    public function testDoesNotLogWhenBrokerNacksBecausePublishThrowsFirst(): void
    {
        $channel = $this->channelBase();

        $nackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(static fn() => null);
        $channel->method('set_nack_handler')->willReturnCallback(
            function (callable $cb) use (&$nackHandler): void {
                $nackHandler = $cb;
            }
        );

        $publishedMessage = null;
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $msg) use (&$publishedMessage): void {
                $publishedMessage = $msg;
            }
        );
        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$nackHandler, &$publishedMessage): void {
                ($nackHandler)($publishedMessage);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $this->expectException(RabbitPublishFailedException::class);

        $this->adapterWith($channel, $logger)->publishAll([$this->makeMessage()]);
    }

    public function testPublisherExceptionPropagatesUncaught(): void
    {
        $channel = $this->channelBase();

        $nackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(static fn() => null);
        $channel->method('set_nack_handler')->willReturnCallback(
            function (callable $cb) use (&$nackHandler): void {
                $nackHandler = $cb;
            }
        );

        $publishedMessage = null;
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $msg) use (&$publishedMessage): void {
                $publishedMessage = $msg;
            }
        );
        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$nackHandler, &$publishedMessage): void {
                ($nackHandler)($publishedMessage);
            }
        );

        $this->expectException(RabbitPublishFailedException::class);

        $this->adapterWith($channel)->publishAll([$this->makeMessage()]);
    }

    /** @return AMQPChannel&MockObject */
    private function channelBase(): AMQPChannel&MockObject
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);
        return $channel;
    }

    /**
     * Returns an AMQPChannel mock that captures every basic_publish call into
     * $captured (one entry per publish) and simulates a broker ack for each
     * published message on wait_for_pending_acks.
     *
     * @param list<array{
     *     exchange: string,
     *     routingKey: string,
     *     body: string,
     *     properties: array<string, mixed>
     * }> $captured by reference
     * @return AMQPChannel&MockObject
     */
    private function ackingChannelCapturingPublish(
        array &$captured,
        ?int $expectedConfirmWaits = null
    ): AMQPChannel&MockObject {
        $channel = $this->channelBase();

        $ackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(
            function (callable $cb) use (&$ackHandler): void {
                $ackHandler = $cb;
            }
        );
        $channel->method('set_nack_handler')->willReturnCallback(static fn() => null);

        /** @var list<AMQPMessage> $publishedMessages */
        $publishedMessages = [];
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $msg, string $ex, string $rk) use (&$publishedMessages, &$captured): void {
                $publishedMessages[] = $msg;
                $captured[] = [
                    'exchange' => $ex,
                    'routingKey' => $rk,
                    'body' => $msg->getBody(),
                    'properties' => $msg->get_properties(),
                ];
            }
        );
        $channel->expects($expectedConfirmWaits === null ? self::any() : self::exactly($expectedConfirmWaits))
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                function () use (&$ackHandler, &$publishedMessages): void {
                    self::assertNotNull($ackHandler);
                    foreach ($publishedMessages as $message) {
                        ($ackHandler)($message);
                    }
                }
            );

        return $channel;
    }

    private function adapterWith(
        AMQPChannel $channel,
        ?LoggerInterface $logger = null
    ): RabbitReleaseNotificationPublisher {
        return new RabbitReleaseNotificationPublisher(
            new RabbitPublisher(new RabbitConnection($channel)),
            new SendReleaseEmailSerializer(),
            $logger ?? new NullLogger(),
        );
    }

    private function makeMessage(int $subscriptionId = 123): SendReleaseEmail
    {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            sprintf('11111111-2222-4333-8444-%012d', $subscriptionId),
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            $subscriptionId,
            new EmailAddress('user' . $subscriptionId . '@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release v1.2.3',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                (new \DateTimeImmutable('2026-06-07T11:00:00+00:00'))->format(\DateTimeInterface::RFC3339),
                'release notes body',
            ),
        );
    }
}
