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
 * Welcome use-case — the claim/render/send/markSent flow keyed for welcome,
 * modeled on {@see SendReleaseEmailHandler}, with two HW9 additions: it publishes
 * a `WelcomeEmailOutcome` reply on every disposition (FR7), and it owns a terminal
 * branch ({@see handleTerminal()}) that the consumer invokes once the retry bound
 * is exhausted.
 *
 * The four claim outcomes (read via if-chains, never an exhaustive match, so the
 * shared {@see ClaimOutcome} enum stays additive):
 * - AlreadySent  → dedup, publish `sent`, return (no second email, FR6).
 * - AlreadyFailed→ re-publish `failed`, throw {@see WelcomeAlreadyFailedException}
 *   so the consumer DLQs (no re-send) — the redelivery-after-failed-reply path.
 * - InFlight     → throw {@see WelcomeInFlightException} (consumer parks the lease).
 * - Claimed      → render + send; on send throw recordFailedAttempt + rethrow
 *   (bounded retry); markSent true → publish `sent`; false (fenced) → superseded.
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
            // Redelivery after a prior success: dedup, but STILL reply `sent` so the
            // saga can complete instead of silently dropping the outcome.
            $this->record(fn() => $this->stats->recordWelcomeDeduped());
            $this->publishSent($email);
            return;
        }

        if ($claim->outcome === ClaimOutcome::AlreadyFailed) {
            // Redelivery after a terminal failure: re-emit `failed`, do NOT re-send,
            // and ask the consumer to complete the DLQ disposition.
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

        // Fenced: our lease was taken over mid-send — the new holder owns the
        // ledger row and replies for it. Returning normally still acks; retrying
        // would only send a third copy.
    }

    /**
     * Terminal branch (FR7) — invoked by the consumer when the retry bound is
     * exhausted. Persist `terminal_failed_at` BEFORE publishing `failed` so that a
     * redelivery after an unconfirmed reply-publish finds AlreadyFailed (re-emit,
     * no re-send). The publisher fails closed: an unconfirmed publish throws, the
     * message is left for redelivery, and the process exits for supervised restart
     * — the terminal marker makes that redelivery idempotent.
     */
    public function handleTerminal(WelcomeEmail $email, string $error): void
    {
        $this->ledger->markTerminalFailed($email->key(), $error);
        $this->record(fn() => $this->stats->recordWelcomeFailed());
        $this->publishFailed($email, $error);
    }

    /**
     * `welcome_reply_published_total` is at-least-once, not exactly-once: under the
     * fail-closed restart loop a redelivery re-emits the same disposition (e.g.
     * AlreadySent → `sent`, AlreadyFailed → `failed`), so this counter can exceed
     * `welcome_sent_total` / `welcome_failed_total` by the number of restarts. That
     * is within the documented at-least-once delivery bound (NFR1); the consumer of
     * the reply is idempotent on `sagaId`.
     */
    private function publishSent(WelcomeEmail $email): void
    {
        $this->publisher->publish($email->sagaId, $email->subscriptionId, WelcomeOutcome::Sent);
        $this->record(fn() => $this->stats->recordWelcomeReplyPublished());
    }

    /** @see publishSent() — `welcome_reply_published_total` is at-least-once here too. */
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
            // swallowed — metric write must not change message disposition
        }
    }
}
