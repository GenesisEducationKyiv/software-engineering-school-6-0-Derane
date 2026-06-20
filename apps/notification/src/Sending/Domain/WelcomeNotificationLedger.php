<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Persistent dedup ledger for welcome notifications (one per subscription, FR6).
 *
 * Mirrors {@see NotificationLedger}'s atomic claim/fencing-token contract, with
 * two welcome-specific differences:
 * - keyed by {@see WelcomeNotificationKey} (subscriptionId only); and
 * - a terminal-failure state: {@see markTerminalFailed()} stamps
 *   `terminal_failed_at`, after which {@see claim()} resolves to
 *   {@see ClaimOutcome::AlreadyFailed} forever — a terminally-undeliverable
 *   welcome is never re-claimed and never re-sent (FR7). This is what makes a
 *   redelivery after a failed-reply-publish failure idempotent: the prior
 *   `failed` reply is re-emitted, the email is not re-sent.
 *
 * The four outcomes claim() resolves: Claimed (lease won), InFlight (live lease
 * held by another worker), AlreadySent (sent_at set), AlreadyFailed
 * (terminal_failed_at set).
 */
interface WelcomeNotificationLedger
{
    /**
     * A claim older than this is considered abandoned and may be re-claimed.
     * Same lease window as the release ledger.
     */
    public const CLAIM_LEASE_SECONDS = 300;

    public function claim(WelcomeNotificationKey $key, EmailAddress $email): ClaimResult;

    /**
     * Mark a claimed welcome as delivered. Returns true when the row was updated;
     * false when the presented token no longer matches (a fenced no-op — the lease
     * was taken over, so this worker's send was a superseded duplicate).
     */
    public function markSent(WelcomeNotificationKey $key, EmailAddress $email, string $claimToken): bool;

    /**
     * Record a failed attempt and release the claim so a redelivery can retry;
     * a stale token is a fenced no-op. Does NOT set terminal_failed_at — the
     * welcome is still re-claimable for bounded retry.
     */
    public function recordFailedAttempt(
        WelcomeNotificationKey $key,
        EmailAddress $email,
        string $error,
        string $claimToken
    ): void;

    /**
     * Mark the welcome terminally failed: stamp `terminal_failed_at` and the
     * `last_error`, clearing the lease. Called once, BEFORE the terminal `failed`
     * reply is published, so a redelivery after an unconfirmed reply-publish finds
     * AlreadyFailed (re-emit reply, no re-send). Token-less: the terminal decision
     * is the consumer's, taken after the retry bound is exhausted.
     */
    public function markTerminalFailed(WelcomeNotificationKey $key, string $error): void;
}
