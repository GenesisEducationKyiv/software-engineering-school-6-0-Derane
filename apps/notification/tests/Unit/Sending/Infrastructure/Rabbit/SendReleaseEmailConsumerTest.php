<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Domain\DeliveryOutcomeRecorder;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\MessageProcessingStatsRecorder;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * RabbitConsumer and RabbitConnection are final readonly and cannot be doubled
 * by PHPUnit — tests wire the real classes against a mocked AMQPChannel.
 *
 * Malformed-message fixtures carry an x-retry-count below MAX_REDELIVERIES so
 * shouldRouteToDlq() would return false if consulted. Tests asserting
 * basic_nack(requeue:false) prove handleDelivery() short-circuits to the
 * poison-message branch before ever reaching the retry-bound check.
 */
final class SendReleaseEmailConsumerTest extends TestCase
{
    private const QUEUE = 'notifications.send-email';

    private const VALID_JSON = <<<'JSON'
    {
      "schema": "SendReleaseEmail/v1",
      "eventId": "11111111-1111-4111-8111-111111111111",
      "occurredAt": "2026-06-07T12:00:00+00:00",
      "subscriptionId": 42,
      "email": "subscriber@example.com",
      "repository": "owner/repo",
      "release": {
        "tagName": "v1.2.3",
        "name": "Release name",
        "body": "Release body text.",
        "htmlUrl": "https://github.com/owner/repo/releases/tag/v1.2.3",
        "publishedAt": "2026-06-07T11:00:00+00:00"
      }
    }
    JSON;

    private const INVALID_JSON = '{not valid json';

    private const MISSING_FIELD_JSON = '{"schema":"SendReleaseEmail/v1"}';

    /** @var NotificationLedger&MockObject */
    private NotificationLedger $ledger;
    /** @var EmailRenderer&MockObject */
    private EmailRenderer $renderer;
    /** @var Mailer&MockObject */
    private Mailer $mailer;
    /** @var DeliveryOutcomeRecorder&MockObject */
    private DeliveryOutcomeRecorder $outcomes;
    /** @var MessageProcessingStatsRecorder&MockObject */
    private MessageProcessingStatsRecorder $stats;

    #[\Override]
    protected function setUp(): void
    {
        $this->ledger = $this->createMock(NotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->outcomes = $this->createMock(DeliveryOutcomeRecorder::class);
        $this->stats = $this->createMock(MessageProcessingStatsRecorder::class);
    }

    public function testAcksOnSuccessfulHandling(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        $message = $this->deliveredMessage($channel, deliveryTag: 1);
        $this->configureHandlerForSuccess();
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with('subscriber@example.com', self::isInstanceOf(RenderedEmail::class));
        $this->ledger->expects(self::once())->method('markSent')
            ->with(42, 'v1.2.3', 'owner/repo', 'subscriber@example.com');

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['ack' => 1], $captured);
    }

    public function testAcksOnIdempotentSkipJustLikeSuccess(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        $message = $this->deliveredMessage($channel, deliveryTag: 2);
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');

        $this->ledger->method('hasBeenSent')->willReturn(true);
        $this->renderer->expects(self::never())->method('render');
        $this->mailer->expects(self::never())->method('send');
        $this->ledger->expects(self::never())->method('markSent');

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['ack' => 2], $captured);
    }

    public function testRoutesMalformedJsonStraightToDlqWithoutCheckingRedeliveries(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        // Carries an x-retry-count BELOW MAX_REDELIVERIES: were the bound-check
        // consulted for this path, shouldRouteToDlq() would return false, and
        // requeueWithRetry() would fire (basic_publish + basic_ack). Observing
        // nack(..., false) proves the poison branch never asks.
        $message = $this->deliveredMessageWithRetryCount(
            $channel,
            deliveryTag: 3,
            body: self::INVALID_JSON,
            retryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
        );
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::once())->method('recordDlq');

        $this->ledger->expects(self::never())->method('hasBeenSent');
        $this->mailer->expects(self::never())->method('send');

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['nack' => [3, false]], $captured);
    }

    public function testRoutesMessageWithMissingRequiredFieldStraightToDlq(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        // Same below-the-bound fixture as the undecodable-JSON case — see that
        // test's comment for why this proves the bound-check is never consulted.
        $message = $this->deliveredMessageWithRetryCount(
            $channel,
            deliveryTag: 4,
            body: self::MISSING_FIELD_JSON,
            retryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
        );
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::once())->method('recordDlq');

        $this->ledger->expects(self::never())->method('hasBeenSent');
        $this->mailer->expects(self::never())->method('send');

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['nack' => [4, false]], $captured);
    }

    public function testRequeuesOnTransientFailureBelowRedeliveryBound(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        $message = $this->deliveredMessageWithRetryCount(
            $channel,
            deliveryTag: 5,
            body: self::VALID_JSON,
            retryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
        );
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');
        $this->configureHandlerToThrowFromMailer(new \RuntimeException('SMTP timeout'));

        $this->consumerWith($channel)->handleDelivery($message);

        // requeueWithRetry: basic_publish fires first, then basic_ack
        self::assertSame(['published' => true, 'ack' => 5], $captured);
    }

    public function testRoutesToDlqWhenRedeliveryBoundExceeded(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        $message = $this->deliveredMessageWithRetryCount(
            $channel,
            deliveryTag: 6,
            body: self::VALID_JSON,
            retryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES,
        );
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordFailed');
        $this->stats->expects(self::once())->method('recordDlq');
        $this->configureHandlerToThrowFromMailer(new \RuntimeException('SMTP still down'));

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['nack' => [6, false]], $captured);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Wires the mocked ports so the real handler runs its full happy path. */
    private function configureHandlerForSuccess(): void
    {
        $this->ledger->method('hasBeenSent')->willReturn(false);
        $this->renderer->method('render')->willReturn(new RenderedEmail('Subject', '<p>html</p>', 'text'));
        $this->mailer->method('send');
        $this->ledger->method('markSent');
    }

    /** Wires the mocked ports so the real handler propagates a Mailer failure uncaught. */
    private function configureHandlerToThrowFromMailer(\Throwable $exception): void
    {
        $this->ledger->method('hasBeenSent')->willReturn(false);
        $this->renderer->method('render')->willReturn(new RenderedEmail('Subject', '<p>html</p>', 'text'));
        $this->mailer->method('send')->willThrowException($exception);
        $this->ledger->method('recordFailedAttempt');
    }

    /** Builds the consumer-under-test wired with the REAL RabbitConsumer/RabbitConnection chain. */
    private function consumerWith(AMQPChannel $channel): SendReleaseEmailConsumer
    {
        $rabbitConsumer = new RabbitConsumer($this->connectionWrapping($channel));
        $handler = new SendReleaseEmailHandler($this->ledger, $this->renderer, $this->mailer, $this->outcomes);
        $mapper = new SendReleaseEmailMessageMapper();

        return new SendReleaseEmailConsumer($rabbitConsumer, $handler, $mapper, $this->stats);
    }

    /**
     * Returns an AMQPChannel mock that records ack/nack/publish outcomes into
     * $captured by reference.
     *
     * - `basic_publish`: sets `$captured['published'] = true` (additive)
     * - `basic_ack`: sets `$captured['ack'] = $deliveryTag` (additive)
     * - `basic_nack`: sets `$captured = ['nack' => [$deliveryTag, $requeue]]` (overwrites)
     *
     * For `requeueWithRetry()`, publish fires before ack, so the final map is
     * `['published' => true, 'ack' => $deliveryTag]`.
     *
     * @param array<string, mixed> $captured passed by reference
     * @return AMQPChannel&MockObject
     */
    private function ackingNackingChannelCapturing(array &$captured): AMQPChannel&MockObject
    {
        $channel = $this->channelBase();

        $channel->method('basic_publish')->willReturnCallback(
            function () use (&$captured): void {
                $captured['published'] = true;
            }
        );
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

        return $channel;
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

    private function connectionWrapping(AMQPChannel $channel): RabbitConnection
    {
        return new RabbitConnection($channel);
    }

    /** A freshly-delivered message — no `x-retry-count` header (first delivery). */
    private function deliveredMessage(AMQPChannel $channel, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage(self::VALID_JSON);
        $message->setChannel($channel)->setDeliveryInfo($deliveryTag, false, 'notifications', 'release.email');

        return $message;
    }

    /**
     * A message carrying an `x-retry-count` application header — drives
     * `RabbitConsumer::shouldRouteToDlq()`'s real bounded-retry arithmetic.
     */
    private function deliveredMessageWithRetryCount(
        AMQPChannel $channel,
        int $deliveryTag,
        string $body,
        int $retryCount,
    ): AMQPMessage {
        $message = new AMQPMessage($body, [
            'application_headers' => new AMQPTable(['x-retry-count' => $retryCount]),
        ]);
        $message->setChannel($channel)->setDeliveryInfo($deliveryTag, true, 'notifications', 'release.email');

        return $message;
    }
}
