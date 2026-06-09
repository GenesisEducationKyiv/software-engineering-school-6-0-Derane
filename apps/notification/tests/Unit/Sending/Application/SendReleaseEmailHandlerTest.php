<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Application;

use App\Sending\Application\NotificationInFlightException;
use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Application\DeliveryOutcomeRecorder;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\NotificationKey;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\RenderedEmail;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SendReleaseEmailHandlerTest extends TestCase
{
    private const CLAIM_TOKEN = 'aaaabbbbccccddddeeeeffff00001111';

    private NotificationLedger&MockObject $ledger;
    private EmailRenderer&MockObject $renderer;
    private Mailer&MockObject $mailer;
    private DeliveryOutcomeRecorder&MockObject $outcomes;
    private SendReleaseEmailHandler $handler;

    protected function setUp(): void
    {
        $this->ledger = $this->createMock(NotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->outcomes = $this->createMock(DeliveryOutcomeRecorder::class);

        $this->handler = new SendReleaseEmailHandler($this->ledger, $this->renderer, $this->mailer, $this->outcomes);
    }

    private function email(): ReleaseEmail
    {
        return new ReleaseEmail(
            eventId: '11111111-1111-4111-8111-111111111111',
            subscriptionId: 42,
            recipientEmail: 'subscriber@example.com',
            repository: 'owner/repo',
            tagName: 'v1.2.3',
            releaseName: 'Release name',
            releaseBody: 'Release description text.',
            releaseUrl: 'https://github.com/owner/repo/releases/tag/v1.2.3',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );
    }

    private function key(): NotificationKey
    {
        return new NotificationKey(42, 'v1.2.3', 'owner/repo');
    }

    public function testSkipsAlreadySentEmailAsDedupe(): void
    {
        $email = $this->email();

        $this->ledger->expects(self::once())
            ->method('claim')
            ->with($this->key(), 'subscriber@example.com')
            ->willReturn(ClaimResult::alreadySent());

        $this->renderer->expects(self::never())->method('render');
        $this->mailer->expects(self::never())->method('send');
        $this->ledger->expects(self::never())->method('markSent');
        $this->outcomes->expects(self::once())->method('recordDeduped');
        $this->outcomes->expects(self::never())->method('recordDelivered');

        $this->handler->handle($email);
    }

    public function testThrowsWithoutSendingWhenAnotherWorkerHoldsTheClaim(): void
    {
        $email = $this->email();

        $this->ledger->expects(self::once())
            ->method('claim')
            ->willReturn(ClaimResult::inFlight());

        $this->renderer->expects(self::never())->method('render');
        $this->mailer->expects(self::never())->method('send');
        $this->ledger->expects(self::never())->method('markSent');
        $this->ledger->expects(self::never())->method('recordFailedAttempt');
        $this->outcomes->expects(self::never())->method('recordDeduped');
        $this->outcomes->expects(self::never())->method('recordDelivered');

        $this->expectException(NotificationInFlightException::class);

        $this->handler->handle($email);
    }

    public function testRendersAndSendsAndRecordsWhenClaimIsWon(): void
    {
        $email = $this->email();
        $rendered = new RenderedEmail('New Release: owner/repo v1.2.3', '<p>html</p>', 'text');

        $this->ledger->expects(self::once())
            ->method('claim')
            ->with($this->key(), 'subscriber@example.com')
            ->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));

        $this->renderer->expects(self::once())
            ->method('render')
            ->with($email)
            ->willReturn($rendered);

        $this->mailer->expects(self::once())
            ->method('send')
            ->with('subscriber@example.com', $rendered);

        $this->ledger->expects(self::once())
            ->method('markSent')
            ->with($this->key(), 'subscriber@example.com', self::CLAIM_TOKEN);
        $this->outcomes->expects(self::once())->method('recordDelivered');
        $this->outcomes->expects(self::never())->method('recordDeduped');

        $this->handler->handle($email);
    }

    public function testRecordsFailedAttemptWithTheClaimTokenAndRethrowsWhenMailerThrows(): void
    {
        $email = $this->email();
        $rendered = new RenderedEmail('New Release: owner/repo v1.2.3', '<p>html</p>', 'text');

        $this->ledger->method('claim')->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));
        $this->renderer->method('render')->willReturn($rendered);

        $this->mailer->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP timeout'));

        $this->ledger->expects(self::once())
            ->method('recordFailedAttempt')
            ->with($this->key(), 'subscriber@example.com', 'SMTP timeout', self::CLAIM_TOKEN);

        $this->ledger->expects(self::never())->method('markSent');
        $this->outcomes->expects(self::never())->method('recordDelivered');
        $this->outcomes->expects(self::never())->method('recordDeduped');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP timeout');

        $this->handler->handle($email);
    }

    public function testDoesNotMarkSentWhenMailerThrows(): void
    {
        $email = $this->email();
        $rendered = new RenderedEmail('New Release: owner/repo v1.2.3', '<p>html</p>', 'text');

        $this->ledger->method('claim')->willReturn(ClaimResult::claimed(self::CLAIM_TOKEN));
        $this->renderer->method('render')->willReturn($rendered);

        $this->mailer->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP timeout'));

        $this->ledger->expects(self::never())->method('markSent');
        $this->outcomes->expects(self::never())->method('recordDelivered');
        $this->outcomes->expects(self::never())->method('recordDeduped');

        $this->expectException(\RuntimeException::class);

        $this->handler->handle($email);
    }
}
