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
 * Tests SendReleaseEmailConsumer by wiring it with a real (mocked-channel)
 * RabbitConsumer and RabbitConnection — both are final readonly classes and
 * cannot be doubled by PHPUnit. The underlying AMQPChannel is mocked to
 * simulate broker ack/nack acknowledgement without a live broker, and real
 * AMQPMessage fixtures (optionally carrying crafted `application_headers`/
 * `x-death` records) stand in for delivered messages — mirroring
 * RabbitConsumerTest's (C4) `connectionWrapping()`/`deliveredMessage()`/
 * `messageWithXDeath()` pattern and RabbitReleaseNotificationPublisherTest's
 * (C5) "real final-readonly collaborator + mocked AMQPChannel" approach.
 *
 * The most important test in story D4 — independently proves all branches of
 * the malformed-vs-transient design (Technical Decisions §1): ack-on-success,
 * ack-on-idempotent-skip, two distinct poison-message-straight-to-DLQ
 * scenarios (undecodable JSON, missing required field), transient-failure
 * bounded-retry, and transient-failure-exhausted-to-DLQ.
 *
 * ## Proving "malformed messages never consult the bound-check" without
 * mocking RabbitConsumer directly
 *
 * The original draft of this test mocked `RabbitConsumer` and asserted
 * `expects(self::never())->method('shouldRouteToDlq')` on the malformed-message
 * path. With the REAL `RabbitConsumer` wired in, that call is no longer an
 * interceptable collaborator method — `shouldRouteToDlq()` only ever reads
 * the message's own `application_headers`, so "never consulted" cannot be
 * observed by spying on it directly.
 *
 * Instead, {@see self::testRoutesMalformedJsonStraightToDlqWithoutCheckingRedeliveries()}
 * and {@see self::testRoutesMessageWithMissingRequiredFieldStraightToDlq()} prove
 * the same claim *behaviourally*: each malformed fixture carries an `x-death`
 * header whose redelivery count for `notifications.send-email` sits **below**
 * `SendReleaseEmailConsumer::MAX_REDELIVERIES` — i.e. `shouldRouteToDlq()`
 * would return `false` if it were consulted, which (per the transient-failure
 * branch) would produce `nack(requeue: true)`. The test asserts the broker
 * instead receives `basic_nack(..., requeue: false)` — straight to the DLQ on
 * first sighting, regardless of what the bound-check would have said. This is
 * only possible if `handleDelivery()` short-circuits to the poison-message
 * branch *before* ever calling `shouldRouteToDlq()` — exactly the contract
 * the class docblock describes ("no bound check, ever, for this path").
 *
 * ## Two test-double design notes worth documenting
 *
 * 1. `RabbitConsumer`/`RabbitConnection` are wired for real over a mocked
 *    `AMQPChannel` (see above) — needed to prove the ack/nack/DLQ-routing
 *    outcomes, the very crux of this story's branching contract, against the
 *    real bounded-retry implementation rather than a stand-in for it.
 *
 * 2. `SendReleaseEmailMessageMapper` and `SendReleaseEmailHandler` are NOT
 *    mocked — both are `final readonly class` (the mapper by this story's own
 *    design choice; the handler is D3's shipped, reviewed deliverable this
 *    story must not touch), and PHPUnit 10's native MockObject unconditionally
 *    refuses to double `final`/`readonly` classes (`ClassIsFinalException`/
 *    `ClassIsReadonlyException` — no opt-out in this version). Using REAL
 *    instances is not a compromise here, though — it is arguably the stronger
 *    design:
 *    - the REAL mapper, fed crafted JSON bodies (valid / undecodable / missing
 *      a required field), proves the consumer's poison-detection branch
 *      against the actual deserialization contract `SendReleaseEmailMessageMapperTest`
 *      pins exhaustively elsewhere — exactly what AC4 cares about end-to-end;
 *    - the REAL handler, wired with mocked `NotificationLedger`/`EmailRenderer`/`Mailer`
 *      port doubles (all interfaces — freely mockable), lets this test drive
 *      "handler succeeds" / "handler throws" through `Mailer::send()` — the
 *      natural place to simulate a transient SMTP failure, exactly as the
 *      Technical Decisions narrative frames it ("e.g. Mailer::send() throws an
 *      SMTP exception — propagates uncaught through SendReleaseEmailHandler::handle()
 *      per D3's no-try/catch contract") — proving the consumer's branching
 *      against the handler's REAL propagation behavior.
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
        // Carries an x-death count BELOW MAX_REDELIVERIES: were the bound-check
        // ever consulted for this path, shouldRouteToDlq() would return false,
        // and the transient-failure branch would requeue (nack ..., true).
        // Observing nack(..., false) instead proves the poison branch never
        // asks — it routes to the DLQ unconditionally, on first sighting.
        $message = $this->deliveredMessageWithXDeath(
            $channel,
            deliveryTag: 3,
            body: self::INVALID_JSON,
            redeliveryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
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
        // Same below-the-bound x-death fixture as the undecodable-JSON case —
        // see that test's comment for why this proves the bound-check is
        // never consulted on the poison-message path.
        $message = $this->deliveredMessageWithXDeath(
            $channel,
            deliveryTag: 4,
            body: self::MISSING_FIELD_JSON,
            redeliveryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
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
        $message = $this->deliveredMessageWithXDeath(
            $channel,
            deliveryTag: 5,
            body: self::VALID_JSON,
            redeliveryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES - 1,
        );
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');
        $this->configureHandlerToThrowFromMailer(new \RuntimeException('SMTP timeout'));

        $this->consumerWith($channel)->handleDelivery($message);

        self::assertSame(['nack' => [5, true]], $captured);
    }

    public function testRoutesToDlqWhenRedeliveryBoundExceeded(): void
    {
        $captured = [];
        $channel = $this->ackingNackingChannelCapturing($captured);
        $message = $this->deliveredMessageWithXDeath(
            $channel,
            deliveryTag: 6,
            body: self::VALID_JSON,
            redeliveryCount: SendReleaseEmailConsumer::MAX_REDELIVERIES + 1,
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
     * Returns an AMQPChannel mock that records exactly which acknowledgement
     * the broker received — `['ack' => $deliveryTag]` or
     * `['nack' => [$deliveryTag, $requeue]]` — into $captured by reference.
     * `AMQPMessage::ack()`/`nack()` delegate to `basic_ack`/`basic_nack` on
     * the message's bound channel (set via `setChannel`/`setDeliveryInfo` —
     * see {@see self::deliveredMessage()}), exactly like the real broker
     * round-trip `RabbitConsumerTest` exercises.
     *
     * @param array<string, mixed> $captured passed by reference
     * @return AMQPChannel&MockObject
     */
    private function ackingNackingChannelCapturing(array &$captured): AMQPChannel&MockObject
    {
        $channel = $this->channelBase();

        $channel->method('basic_ack')->willReturnCallback(
            function (int $deliveryTag) use (&$captured): void {
                $captured = ['ack' => $deliveryTag];
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

    /** A freshly-delivered message — no `x-death` header (first delivery). */
    private function deliveredMessage(AMQPChannel $channel, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage(self::VALID_JSON);
        $message->setChannel($channel)->setDeliveryInfo($deliveryTag, false, 'notifications', 'release.email');

        return $message;
    }

    /**
     * A redelivered message carrying an `x-death` record for
     * `notifications.send-email` with the given total `count` — drives
     * `RabbitConsumer::shouldRouteToDlq()`'s real bounded-retry arithmetic,
     * mirroring `RabbitConsumerTest::messageWithXDeath()`.
     */
    private function deliveredMessageWithXDeath(
        AMQPChannel $channel,
        int $deliveryTag,
        string $body,
        int $redeliveryCount,
    ): AMQPMessage {
        $message = new AMQPMessage($body, [
            'application_headers' => new AMQPTable([
                'x-death' => [
                    ['queue' => self::QUEUE, 'reason' => 'rejected', 'count' => $redeliveryCount],
                ],
            ]),
        ]);
        $message->setChannel($channel)->setDeliveryInfo($deliveryTag, true, 'notifications', 'release.email');

        return $message;
    }
}
