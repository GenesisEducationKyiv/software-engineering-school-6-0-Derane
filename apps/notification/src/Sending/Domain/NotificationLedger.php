<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Persistent dedup ledger for release notifications.
 *
 * claim() is the concurrency gate: it must atomically transition the
 * notification into an in-flight state so that two workers processing the
 * same business key (concurrent consumers, or a redelivery racing a slow
 * first attempt) cannot both reach the mailer while the claim is live. A
 * plain check-then-act (hasBeenSent → send → markSent) cannot give that
 * guarantee.
 *
 * A won claim carries a fencing token; markSent()/recordFailedAttempt() only
 * apply when the presented token still matches the row. Accepted bound: a
 * worker stalled past CLAIM_LEASE_SECONDS whose claim is taken over can still
 * produce a duplicate email (its SMTP send already happened) — the fencing
 * guarantees it cannot corrupt the new claim holder's ledger state, not that
 * the duplicate send never leaves the box.
 */
interface NotificationLedger
{
    /**
     * A claim older than this is considered abandoned (worker died between
     * claim and markSent/recordFailedAttempt) and may be re-claimed. Callers
     * that back off on ClaimOutcome::InFlight must wait at least this long
     * before retrying, or they exhaust their retry budget against a claim
     * that was always going to expire.
     */
    public const CLAIM_LEASE_SECONDS = 300;

    public function claim(NotificationKey $key, string $email): ClaimResult;

    /** Mark a claimed notification as delivered; a stale token is a fenced no-op. */
    public function markSent(NotificationKey $key, string $email, string $claimToken): void;

    /**
     * Record a failed attempt and release the claim so a redelivery can
     * retry; a stale token is a fenced no-op.
     */
    public function recordFailedAttempt(NotificationKey $key, string $email, string $error, string $claimToken): void;
}
