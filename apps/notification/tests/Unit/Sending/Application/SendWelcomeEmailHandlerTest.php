<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Application;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeAlreadyFailedException;
use App\Sending\Application\WelcomeInFlightException;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Domain\WelcomeNotificationKey;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SendWelcomeEmailHandlerTest extends TestCase
{
    private const CLAIM_TOKEN = 'aaaabbbbccccddddeeeeffff00001111';

    private WelcomeNotificationLedger&MockObject $ledger;
    private EmailRenderer&MockObject $renderer;
    private Mailer&MockObject $mailer;
    private WelcomeOutcomePublisher&MockObject $publisher;
    private WelcomeProcessingStatsRecorder&MockObject $stats;
    private SendWelcomeEmailHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->ledger = $this->createMock(WelcomeNotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->publisher = $this->createMock(WelcomeOutcomePublisher::class);
        $this->stats = $this->createMock(WelcomeProcessingStatsRecorder::class);

        $this->handler = new SendWelcomeEmailHandler(
            $this->ledger,
            $this->renderer,
            $this->mailer,
            $this->publisher,
            $this->stats,
        );
    }

    private function email(): WelcomeEmail
    {
        return new WelcomeEmail(
            sagaId: 'saga-42',
            subscriptionId: 42,
            recipientEmail: $this->recipient(),
            repository: new RepositoryName('owner/repo'),
        );
    }

    private function key(): WelcomeNotificationKey
    {
        return new WelcomeNotificationKey(42);
    }

    private function recipient(): EmailAddress
    {
        return new EmailAddress('subscriber@example.com');
    }

    public function testFreshClaimRendersSendsMarksSentAndPublishesSent(): void
    {
        $email = $this->email();
        $rendered = new RenderedEmail('Welcome', '<p>hi</p>', 'hi');

        $this->ledger->expects(self::once())
            ->method('claim')
            ->with($this->key(), $this->recipient())
            ->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));
        $this->renderer->expects(self::once())->method('render')->with($email)->willReturn($rendered);
        $this->mailer->expects(self::once())->method('send')->with($this->recipient(), $rendered);
        $this->ledger->expects(self::once())
            ->method('markSent')
            ->with($this->key(), $this->recipient(), self::CLAIM_TOKEN)
            ->willReturn(true);

        $this->publisher->expects(self::once())
            ->method('publish')
            ->with('saga-42', 42, WelcomeOutcome::Sent, null);
        $this->stats->expects(self::once())->method('recordWelcomeSent');
        $this->stats->expects(self::once())->method('recordWelcomeReplyPublished');

        $this->handler->handle($email);
    }

    public function testAlreadySentDedupesPublishesSentAndDoesNotSend(): void
    {
        $email = $this->email();

        $this->ledger->method('claim')->willReturn(ClaimResult::alreadySent());
        $this->renderer->expects(self::never())->method('render');
        $this->mailer->expects(self::never())->method('send');
        $this->ledger->expects(self::never())->method('markSent');

        $this->stats->expects(self::once())->method('recordWelcomeDeduped');
        $this->stats->expects(self::never())->method('recordWelcomeSent');
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with('saga-42', 42, WelcomeOutcome::Sent, null);

        $this->handler->handle($email);
    }

    public function testAlreadyFailedRepublishesFailedThrowsAndDoesNotResend(): void
    {
        $email = $this->email();

        $this->ledger->method('claim')->willReturn(ClaimResult::alreadyFailed());
        $this->mailer->expects(self::never())->method('send');
        $this->ledger->expects(self::never())->method('markSent');

        $this->publisher->expects(self::once())
            ->method('publish')
            ->with('saga-42', 42, WelcomeOutcome::Failed, self::isType('string'));

        $this->expectException(WelcomeAlreadyFailedException::class);

        $this->handler->handle($email);
    }

    public function testInFlightThrowsWithoutSendingOrPublishing(): void
    {
        $email = $this->email();

        $this->ledger->method('claim')->willReturn(ClaimResult::inFlight());
        $this->mailer->expects(self::never())->method('send');
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(WelcomeInFlightException::class);

        $this->handler->handle($email);
    }

    public function testTransientMailerFailureRecordsAttemptAndRethrowsWithoutPublishing(): void
    {
        $email = $this->email();

        $this->ledger->method('claim')->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP timeout'));

        $this->ledger->expects(self::once())
            ->method('recordFailedAttempt')
            ->with($this->key(), $this->recipient(), 'SMTP timeout', self::CLAIM_TOKEN);
        $this->ledger->expects(self::never())->method('markSent');
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP timeout');

        $this->handler->handle($email);
    }

    public function testFencedMarkSentIsASupersededNoOpThatDoesNotPublish(): void
    {
        $email = $this->email();

        $this->ledger->method('claim')->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->expects(self::once())->method('send');
        // Lease taken over mid-send → markSent fenced.
        $this->ledger->method('markSent')->willReturn(false);

        $this->stats->expects(self::never())->method('recordWelcomeSent');
        $this->publisher->expects(self::never())->method('publish');

        $this->handler->handle($email);
    }

    public function testHandleTerminalMarksTerminalFailedBeforePublishingFailed(): void
    {
        $email = $this->email();

        $callOrder = [];
        $this->ledger->expects(self::once())
            ->method('markTerminalFailed')
            ->with($this->key(), 'gave up')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'markTerminalFailed';
            });
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with('saga-42', 42, WelcomeOutcome::Failed, 'gave up')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'publish';
            });
        $this->stats->expects(self::once())->method('recordWelcomeFailed');
        $this->stats->expects(self::once())->method('recordWelcomeReplyPublished');

        $this->handler->handleTerminal($email, 'gave up');

        self::assertSame(['markTerminalFailed', 'publish'], $callOrder);
    }

    public function testHandleTerminalLeavesReplyUnrecordedWhenPublishFailsClosed(): void
    {
        $email = $this->email();

        $this->ledger->expects(self::once())->method('markTerminalFailed');
        // Fail-closed publisher: an unconfirmed reply throws and must propagate.
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('unconfirmed'));
        $this->stats->expects(self::once())->method('recordWelcomeFailed');
        $this->stats->expects(self::never())->method('recordWelcomeReplyPublished');

        $this->expectException(\RuntimeException::class);

        $this->handler->handleTerminal($email, 'gave up');
    }
}
