<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailMessageMapper;
use App\Sending\Infrastructure\Rabbit\WelcomeOutcomePublishFailedException;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The consumer's ack/park/retry/DLQ/terminal DECISIONS are asserted against a
 * doubled MessageConsumer port plus a real handler over doubled domain ports —
 * no AMQP channel involved.
 */
final class SendWelcomeEmailConsumerTest extends TestCase
{
    private const VALID_JSON = <<<'JSON'
    {
      "schema": "SendWelcomeEmail/v1",
      "sagaId": "5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33",
      "subscriptionId": 123,
      "email": "user@example.com",
      "repository": "owner/repo",
      "occurredAt": "2026-06-20T12:00:00+00:00"
    }
    JSON;

    private const INVALID_JSON = '{not valid json';

    private MessageConsumer&MockObject $consumer;
    private WelcomeNotificationLedger&MockObject $ledger;
    private EmailRenderer&MockObject $renderer;
    private Mailer&MockObject $mailer;
    private WelcomeOutcomePublisher&MockObject $publisher;
    private WelcomeProcessingStatsRecorder&MockObject $stats;

    #[\Override]
    protected function setUp(): void
    {
        $this->consumer = $this->createMock(MessageConsumer::class);
        $this->ledger = $this->createMock(WelcomeNotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->publisher = $this->createMock(WelcomeOutcomePublisher::class);
        $this->stats = $this->createMock(WelcomeProcessingStatsRecorder::class);
    }

    public function testAcksOnSuccessfulHandling(): void
    {
        $message = $this->message();
        $this->configureFreshSuccess();
        $this->stats->expects(self::once())->method('recordWelcomeConsumed');

        $this->consumer->expects(self::once())->method('ack')->with(self::identicalTo($message));
        $this->consumer->expects(self::never())->method('nack');

        $this->sut()->handleDelivery($message);
    }

    public function testAcksOnIdempotentDedupe(): void
    {
        $message = $this->message();
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadySent());
        $this->mailer->expects(self::never())->method('send');

        $this->consumer->expects(self::once())->method('ack')->with(self::identicalTo($message));
        $this->consumer->expects(self::never())->method('nack');

        $this->sut()->handleDelivery($message);
    }

    public function testRoutesMalformedMessageStraightToDlq(): void
    {
        $message = $this->message(self::INVALID_JSON);
        $this->stats->expects(self::once())->method('recordWelcomeConsumed');

        $this->ledger->expects(self::never())->method('claim');
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');
        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);

        $this->sut()->handleDelivery($message);
    }

    public function testParksOnClaimContentionWithoutConsumingRetryBudget(): void
    {
        $message = $this->message();
        $this->ledger->method('claim')->willReturn(ClaimResult::inFlight());
        $this->mailer->expects(self::never())->method('send');

        $this->consumer->expects(self::once())
            ->method('requeueWithoutRetryIncrement')
            ->with(
                self::identicalTo($message),
                SendWelcomeEmailConsumer::RETRY_QUEUE,
                WelcomeNotificationLedger::CLAIM_LEASE_SECONDS,
            );
        $this->consumer->expects(self::never())->method('nack');
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');

        $this->sut()->handleDelivery($message);
    }

    public function testRequeuesForRetryOnTransientFailureBelowTheBound(): void
    {
        $message = $this->message();
        $this->configureTransientMailerFailure();
        $this->consumer->expects(self::once())
            ->method('shouldRouteToDlq')
            ->with(self::identicalTo($message), SendWelcomeEmailConsumer::MAX_REDELIVERIES)
            ->willReturn(false);

        $this->consumer->expects(self::once())
            ->method('requeueWithRetry')
            ->with(self::identicalTo($message), SendWelcomeEmailConsumer::RETRY_QUEUE);
        $this->consumer->expects(self::never())->method('nack');
        $this->ledger->expects(self::never())->method('markTerminalFailed');

        $this->sut()->handleDelivery($message);
    }

    public function testTerminalBranchMarksTerminalPublishesFailedThenNacksToDlq(): void
    {
        $message = $this->message();
        $this->configureTransientMailerFailure();
        $this->consumer->expects(self::once())
            ->method('shouldRouteToDlq')
            ->with(self::identicalTo($message), SendWelcomeEmailConsumer::MAX_REDELIVERIES)
            ->willReturn(true);

        // FR7: terminal_failed_at BEFORE the failed reply, then nack to DLQ.
        $this->ledger->expects(self::once())->method('markTerminalFailed');
        $this->publisher->expects(self::once())->method('publish');
        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);
        $this->consumer->expects(self::never())->method('requeueWithRetry');

        $this->sut()->handleDelivery($message);
    }

    public function testAlreadyFailedRedeliveryRepublishesFailedAndNacksToDlqWithoutResending(): void
    {
        $message = $this->message();
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadyFailed());
        $this->mailer->expects(self::never())->method('send');

        // The handler re-publishes failed; the consumer completes the DLQ disposition.
        $this->publisher->expects(self::once())->method('publish');
        $this->consumer->expects(self::once())->method('nack')->with(self::identicalTo($message), false);
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');
        $this->consumer->expects(self::never())->method('requeueWithRetry');

        $this->sut()->handleDelivery($message);
    }

    public function testTerminalPublishFailureFailsClosedAndDoesNotNackOrRequeue(): void
    {
        $message = $this->message();
        $this->configureTransientMailerFailure();
        $this->consumer->expects(self::once())
            ->method('shouldRouteToDlq')
            ->with(self::identicalTo($message), SendWelcomeEmailConsumer::MAX_REDELIVERIES)
            ->willReturn(true);

        // terminal_failed_at is still persisted before the publish attempt...
        $this->ledger->expects(self::once())->method('markTerminalFailed');
        // ...but the failed reply cannot be confirmed by the broker.
        $this->publisher->method('publish')
            ->willThrowException(WelcomeOutcomePublishFailedException::brokerNacked());

        // Fail closed: leave the delivery unacked (no nack, no requeue) and let the
        // exception propagate so bin/consumer.php exits for a supervised restart.
        $this->consumer->expects(self::never())->method('nack');
        $this->consumer->expects(self::never())->method('requeueWithRetry');

        $this->expectException(WelcomeOutcomePublishFailedException::class);

        $this->sut()->handleDelivery($message);
    }

    public function testAlreadyFailedReplyPublishFailureFailsClosedInsteadOfReenteringRetryLadder(): void
    {
        $message = $this->message();
        // Redelivery of an already-terminal row: the handler re-publishes `failed`,
        // but the broker cannot confirm it.
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadyFailed());
        $this->mailer->expects(self::never())->method('send');
        $this->publisher->method('publish')
            ->willThrowException(WelcomeOutcomePublishFailedException::confirmTimedOut(5.0));

        // The unconfirmed re-emit MUST exit-for-restart, never re-enter the
        // transient retry ladder (no shouldRouteToDlq consult, no requeue, no nack).
        $this->consumer->expects(self::never())->method('shouldRouteToDlq');
        $this->consumer->expects(self::never())->method('requeueWithRetry');
        $this->consumer->expects(self::never())->method('nack');

        $this->expectException(WelcomeOutcomePublishFailedException::class);

        $this->sut()->handleDelivery($message);
    }

    private function sut(): SendWelcomeEmailConsumer
    {
        $handler = new SendWelcomeEmailHandler(
            $this->ledger,
            $this->renderer,
            $this->mailer,
            $this->publisher,
            $this->stats,
        );

        return new SendWelcomeEmailConsumer(
            $this->consumer,
            $handler,
            new SendWelcomeEmailMessageMapper(),
            $this->stats,
            new NullLogger(),
        );
    }

    private function message(string $body = self::VALID_JSON): AMQPMessage
    {
        return new AMQPMessage($body);
    }

    private function configureFreshSuccess(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence-token'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send');
        $this->ledger->method('markSent')->willReturn(true);
    }

    private function configureTransientMailerFailure(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence-token'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP timeout'));
        $this->ledger->method('recordFailedAttempt');
    }
}
