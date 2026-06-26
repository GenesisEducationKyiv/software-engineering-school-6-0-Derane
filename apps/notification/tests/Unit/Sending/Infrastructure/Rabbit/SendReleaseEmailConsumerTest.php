<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Application\DeliveryOutcomeRecorder;
use App\Sending\Application\MessageProcessingStatsRecorder;
use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The consumer's ack/nack/retry/DLQ DECISIONS are asserted against a doubled
 * MessageConsumer port — no AMQP channel involved. The port's own republish
 * mechanics (retry-count arithmetic, expiration, header preservation) live in
 * RabbitConsumerTest.
 */
final class SendReleaseEmailConsumerTest extends TestCase
{
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

    private MessageConsumer&MockObject $consumer;
    private NotificationLedger&MockObject $ledger;
    private EmailRenderer&MockObject $renderer;
    private Mailer&MockObject $mailer;
    private DeliveryOutcomeRecorder&MockObject $outcomes;
    private MessageProcessingStatsRecorder&MockObject $stats;

    #[\Override]
    protected function setUp(): void
    {
        $this->consumer = $this->createMock(MessageConsumer::class);
        $this->ledger = $this->createMock(NotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->outcomes = $this->createMock(DeliveryOutcomeRecorder::class);
        $this->stats = $this->createMock(MessageProcessingStatsRecorder::class);
    }

    public function testAcksOnSuccessfulHandling(): void
    {
        $message = $this->message();
        $this->configureHandlerForSuccess();
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');

        $this->consumer->expects(self::once())->method('ack')->with(self::identicalTo($message));
        $this->consumer->expects(self::never())->method('nack');
        $this->consumer->expects(self::never())->method('requeueWithRetry');
        $this->consumer->expects(self::never())->method('requeueWithoutRetryIncrement');

        $this->sut()->handleDelivery($message);
    }

    public function testAcksOnIdempotentSkipJustLikeSuccess(): void
    {
        $message = $this->message();
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadySent());
        $this->renderer->expects(self::never())->method('render');
        $this->mailer->expects(self::never())->method('send');
        $this->stats->expects(self::once())->method('recordConsumed');

        $this->consumer->expects(self::once())->method('ack')->with(self::identicalTo($message));
        $this->consumer->expects(self::never())->method('nack');

        $this->sut()->handleDelivery($message);
    }

    public function testRoutesMalformedJsonStraightToDlqWithoutConsultingTheRetryBound(): void
    {
        $message = $this->message(self::INVALID_JSON);
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordDlq');
        $this->stats->expects(self::never())->method('recordFailed');

        $this->ledger->expects(self::never())->method('claim');
        // The poison branch must never ask the retry bound.
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');
        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);

        $this->sut()->handleDelivery($message);
    }

    public function testRoutesMessageWithMissingRequiredFieldStraightToDlq(): void
    {
        $message = $this->message(self::MISSING_FIELD_JSON);
        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordDlq');

        $this->ledger->expects(self::never())->method('claim');
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');
        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);

        $this->sut()->handleDelivery($message);
    }

    public function testRequeuesForRetryOnTransientFailureBelowTheRedeliveryBound(): void
    {
        $message = $this->message();
        $this->configureHandlerToThrowFromMailer(new \RuntimeException('SMTP timeout'));
        $this->consumer->expects(self::once())
            ->method('shouldRouteToDlq')
            ->with(self::identicalTo($message), SendReleaseEmailConsumer::MAX_REDELIVERIES)
            ->willReturn(false);

        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');

        $this->consumer->expects(self::once())
            ->method('requeueWithRetry')
            ->with(self::identicalTo($message), SendReleaseEmailConsumer::RETRY_QUEUE);
        $this->consumer->expects(self::never())->method('nack');

        $this->sut()->handleDelivery($message);
    }

    public function testRoutesToDlqWhenTheRedeliveryBoundIsExceeded(): void
    {
        $message = $this->message();
        $this->configureHandlerToThrowFromMailer(new \RuntimeException('SMTP still down'));
        $this->consumer->expects(self::once())
            ->method('shouldRouteToDlq')
            ->with(self::identicalTo($message), SendReleaseEmailConsumer::MAX_REDELIVERIES)
            ->willReturn(true);

        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordFailed');
        $this->stats->expects(self::once())->method('recordDlq');

        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);
        $this->consumer->expects(self::never())->method('requeueWithRetry');

        $this->sut()->handleDelivery($message);
    }

    public function testParksOnClaimContentionForTheLeaseWindowWithoutConsumingRetryBudget(): void
    {
        $message = $this->message();
        $this->ledger->method('claim')->willReturn(ClaimResult::inFlight());
        $this->mailer->expects(self::never())->method('send');

        $this->stats->expects(self::once())->method('recordConsumed');
        $this->stats->expects(self::once())->method('recordContention');
        $this->stats->expects(self::never())->method('recordFailed');
        $this->stats->expects(self::never())->method('recordDlq');

        $this->consumer->expects(self::once())
            ->method('requeueWithoutRetryIncrement')
            ->with(
                self::identicalTo($message),
                SendReleaseEmailConsumer::RETRY_QUEUE,
                NotificationLedger::CLAIM_LEASE_SECONDS,
            );
        $this->consumer->expects(self::never())->method('nack');
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');

        $this->sut()->handleDelivery($message);
    }

    private function sut(): SendReleaseEmailConsumer
    {
        $handler = new SendReleaseEmailHandler($this->ledger, $this->renderer, $this->mailer, $this->outcomes);

        return new SendReleaseEmailConsumer(
            $this->consumer,
            $handler,
            new SendReleaseEmailMessageMapper(),
            $this->stats,
            new NullLogger(),
        );
    }

    private function message(string $body = self::VALID_JSON): AMQPMessage
    {
        return new AMQPMessage($body);
    }

    private function configureHandlerForSuccess(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence-token'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('Subject', '<p>html</p>', 'text'));
        $this->mailer->method('send');
        $this->ledger->method('markSent')->willReturn(true);
    }

    private function configureHandlerToThrowFromMailer(\Throwable $exception): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence-token'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('Subject', '<p>html</p>', 'text'));
        $this->mailer->method('send')->willThrowException($exception);
        $this->ledger->method('recordFailedAttempt');
    }
}
