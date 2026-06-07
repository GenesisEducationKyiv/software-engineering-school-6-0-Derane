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

        $this->adapterWith($channel)->publish($this->makeMessage());

        self::assertSame('notifications', $captured['exchange']);
        self::assertSame('release.email', $captured['routingKey']);
    }

    public function testPublishesWithPersistentDeliveryMode(): void
    {
        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publish($this->makeMessage());

        self::assertSame(2, $captured['properties']['delivery_mode']);
    }

    public function testPublishesWithApplicationJsonContentType(): void
    {
        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publish($this->makeMessage());

        self::assertSame('application/json', $captured['properties']['content_type']);
    }

    public function testPublishesSerializedJsonBody(): void
    {
        $message = $this->makeMessage();
        $expectedBody = (new SendReleaseEmailSerializer())->toJson($message);

        $captured = [];
        $channel = $this->ackingChannelCapturingPublish($captured);

        $this->adapterWith($channel)->publish($message);

        self::assertSame($expectedBody, $captured['body']);
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

        $this->adapterWith($channel)->publish($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * Returns an AMQPChannel mock that captures basic_publish arguments into
     * $captured and simulates a broker ack on wait_for_pending_acks.
     *
     * @param array<string, mixed> $captured passed by reference
     * @return AMQPChannel&MockObject
     */
    private function ackingChannelCapturingPublish(array &$captured): AMQPChannel&MockObject
    {
        $channel = $this->channelBase();

        $ackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(
            function (callable $cb) use (&$ackHandler): void {
                $ackHandler = $cb;
            }
        );
        $channel->method('set_nack_handler')->willReturnCallback(static fn() => null);

        $publishedMessage = null;
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $msg, string $ex, string $rk) use (&$publishedMessage, &$captured): void {
                $publishedMessage = $msg;
                $captured = [
                    'exchange' => $ex,
                    'routingKey' => $rk,
                    'body' => $msg->getBody(),
                    'properties' => $msg->get_properties(),
                ];
            }
        );
        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$ackHandler, &$publishedMessage): void {
                ($ackHandler)($publishedMessage);
            }
        );

        return $channel;
    }

    private function adapterWith(AMQPChannel $channel): RabbitReleaseNotificationPublisher
    {
        return new RabbitReleaseNotificationPublisher(
            new RabbitPublisher(new RabbitConnection($channel)),
            new SendReleaseEmailSerializer(),
        );
    }

    private function makeMessage(): SendReleaseEmail
    {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            '11111111-2222-4333-8444-555555555555',
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release v1.2.3',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                (new \DateTimeImmutable('2026-06-07T11:00:00+00:00'))->format(\DateTimeInterface::RFC3339),
            ),
        );
    }
}
