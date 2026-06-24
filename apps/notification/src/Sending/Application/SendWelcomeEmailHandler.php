<?php

declare(strict_types=1);

namespace App\Sending\Application;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;

/**
 * Welcome use-case: claim/render/send/markSent. Claim outcomes are read via
 * if-chains, never an exhaustive match, so the shared ClaimOutcome enum stays additive.
 */
final readonly class SendWelcomeEmailHandler
{
    public function __construct(
        private WelcomeNotificationLedger $ledger,
        private EmailRenderer $renderer,
        private Mailer $mailer,
        private WelcomeOutcomePublisher $publisher,
        private WelcomeProcessingStatsRecorder $stats,
    ) {
    }

    public function handle(WelcomeEmail $email): void
    {
        $key = $email->key();

        $claim = $this->ledger->claim($key, $email->recipientEmail);

        if ($claim->outcome === ClaimOutcome::AlreadySent) {
            // Dedup, but STILL reply `sent` so the saga can complete rather than silently drop.
            $this->record(fn() => $this->stats->recordWelcomeDeduped());
            $this->publishSent($email);
            return;
        }

        if ($claim->outcome === ClaimOutcome::AlreadyFailed) {
            // Re-emit `failed`, do NOT re-send; consumer completes the DLQ disposition.
            $this->publishFailed($email, 'welcome notification previously failed terminally');
            throw WelcomeAlreadyFailedException::forKey($key);
        }

        if ($claim->outcome === ClaimOutcome::InFlight) {
            throw WelcomeInFlightException::forKey($key);
        }

        $rendered = $this->renderer->render($email);

        try {
            $this->mailer->send($email->recipientEmail, $rendered);
        } catch (\Throwable $e) {
            $this->ledger->recordFailedAttempt($key, $email->recipientEmail, $e->getMessage(), $claim->token());
            throw $e;
        }

        if ($this->ledger->markSent($key, $email->recipientEmail, $claim->token())) {
            $this->record(fn() => $this->stats->recordWelcomeSent());
            $this->publishSent($email);
            return;
        }

        // Fenced: lease taken over mid-send — the new holder owns the row and replies.
        // Return normally to ack; retrying would only send a third copy.
    }

    /**
     * Persist `terminal_failed_at` BEFORE publishing `failed`: a redelivery after an
     * unconfirmed reply-publish then finds AlreadyFailed (re-emit, no re-send). The
     * publisher fails closed — an unconfirmed publish throws and the message is left
     * for redelivery — so the terminal marker is what makes that redelivery idempotent.
     */
    public function handleTerminal(WelcomeEmail $email, string $error): void
    {
        $this->ledger->markTerminalFailed($email->key(), $error);
        $this->record(fn() => $this->stats->recordWelcomeFailed());
        $this->publishFailed($email, $error);
    }

    /**
     * `welcome_reply_published_total` is at-least-once, not exactly-once: under the
     * fail-closed restart loop a redelivery re-emits the same disposition, so this
     * counter can exceed `welcome_sent_total`/`welcome_failed_total`. The reply
     * consumer is idempotent on `sagaId`.
     */
    private function publishSent(WelcomeEmail $email): void
    {
        $this->publisher->publish($email->sagaId, $email->subscriptionId, WelcomeOutcome::Sent);
        $this->record(fn() => $this->stats->recordWelcomeReplyPublished());
    }

    private function publishFailed(WelcomeEmail $email, string $error): void
    {
        $this->publisher->publish($email->sagaId, $email->subscriptionId, WelcomeOutcome::Failed, $error);
        $this->record(fn() => $this->stats->recordWelcomeReplyPublished());
    }

    /** Metrics are best-effort: a recorder failure must never abort the send flow. */
    private function record(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable) {
        }
    }
}
