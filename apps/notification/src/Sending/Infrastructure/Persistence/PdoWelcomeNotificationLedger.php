<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Persistence;

use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\WelcomeNotificationKey;
use App\Sending\Domain\WelcomeNotificationLedger;

/**
 * Welcome-ledger near-clone of {@see PdoNotificationLedger}, keyed on
 * `subscription_id` alone with one extra terminal-state column.
 *
 * Ledger states are encoded in three nullable timestamps:
 * - sent_at NOT NULL                                   → sent (AlreadySent)
 * - terminal_failed_at NOT NULL                        → terminally failed (AlreadyFailed)
 * - sent_at NULL, terminal NULL, claimed_at NOT NULL   → in flight (claimed)
 * - all three NULL                                     → claimable (fresh / retryable)
 *
 * claim() is a single INSERT … ON CONFLICT DO UPDATE … WHERE: the upsert's row
 * lock makes it atomic. The conditional update only fires for claimable rows
 * (sent_at IS NULL AND terminal_failed_at IS NULL AND a free/expired lease), so
 * sent, terminally-failed, and freshly-claimed rows return nothing and the loser
 * backs off. Every won claim stores a fresh claim_token that markSent /
 * recordFailedAttempt must present, fencing a stalled-then-reclaimed worker.
 */
final readonly class PdoWelcomeNotificationLedger implements WelcomeNotificationLedger
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function claim(WelcomeNotificationKey $key, EmailAddress $email): ClaimResult
    {
        $token = bin2hex(random_bytes(16));

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO welcome_notifications
                 (subscription_id, email, claimed_at, claim_token, attempt_count)
             VALUES (:sub, :email, NOW(), :token, 0)
             ON CONFLICT (subscription_id)
             DO UPDATE SET claimed_at = NOW(), claim_token = excluded.claim_token, email = excluded.email,
                           updated_at = NOW()
             WHERE welcome_notifications.sent_at IS NULL
               AND welcome_notifications.terminal_failed_at IS NULL
               AND (welcome_notifications.claimed_at IS NULL
                    OR welcome_notifications.claimed_at < NOW() - INTERVAL \'%d seconds\')
             RETURNING id',
            WelcomeNotificationLedger::CLAIM_LEASE_SECONDS,
        ));
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':email' => $email->value(),
            ':token' => $token,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return ClaimResult::claimed($token);
        }

        $state = $this->terminalStateFor($key);
        if ($state['sent']) {
            return ClaimResult::alreadySent();
        }
        if ($state['terminal']) {
            return ClaimResult::alreadyFailed();
        }

        return ClaimResult::inFlight();
    }

    #[\Override]
    public function markSent(WelcomeNotificationKey $key, EmailAddress $email, string $claimToken): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE welcome_notifications
             SET sent_at = NOW(), claimed_at = NULL, claim_token = NULL, email = :email,
                 attempt_count = attempt_count + 1, last_error = NULL,
                 updated_at = NOW()
             WHERE subscription_id = :sub
               AND claim_token = :token'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':email' => $email->value(),
            ':token' => $claimToken,
        ]);

        // 0 rows ⇒ the token no longer matches (lease taken over) ⇒ fenced.
        return $stmt->rowCount() > 0;
    }

    #[\Override]
    public function recordFailedAttempt(
        WelcomeNotificationKey $key,
        EmailAddress $email,
        string $error,
        string $claimToken
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE welcome_notifications
             SET claimed_at = NULL, claim_token = NULL, email = :email,
                 attempt_count = attempt_count + 1, last_error = :error,
                 updated_at = NOW()
             WHERE subscription_id = :sub
               AND claim_token = :token'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':email' => $email->value(),
            ':error' => $error,
            ':token' => $claimToken,
        ]);
    }

    #[\Override]
    public function markTerminalFailed(WelcomeNotificationKey $key, string $error): void
    {
        // Idempotent: only stamp the first terminal failure (do not advance the
        // count or clobber the original error on a redelivery that re-reaches the
        // terminal branch). The lease is cleared so the state is unambiguous.
        $stmt = $this->pdo->prepare(
            'UPDATE welcome_notifications
             SET terminal_failed_at = NOW(), claimed_at = NULL, claim_token = NULL,
                 attempt_count = attempt_count + 1, last_error = :error,
                 updated_at = NOW()
             WHERE subscription_id = :sub
               AND sent_at IS NULL
               AND terminal_failed_at IS NULL'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':error' => $error,
        ]);
    }

    /**
     * Reads the row's terminal state. NULL-checks are done as `… IS NOT NULL`
     * boolean expressions in SQL; Postgres returns them as the strings 't'/'f'
     * over PDO, so {@see isTruthy()} normalises rather than a raw `(bool)` cast
     * (which would treat the string 'f' as true).
     *
     * @return array{sent: bool, terminal: bool}
     */
    private function terminalStateFor(WelcomeNotificationKey $key): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT (sent_at IS NOT NULL) AS sent, (terminal_failed_at IS NOT NULL) AS terminal
             FROM welcome_notifications
             WHERE subscription_id = :sub'
        );
        $stmt->execute([':sub' => $key->subscriptionId]);

        /** @var array{sent: bool|int|string|null, terminal: bool|int|string|null}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['sent' => false, 'terminal' => false];
        }

        return [
            'sent' => $this->isTruthy($row['sent']),
            'terminal' => $this->isTruthy($row['terminal']),
        ];
    }

    private function isTruthy(bool|int|string|null $value): bool
    {
        // Postgres boolean over PDO arrives as 't'/'f' (string) under the default
        // driver, or true/false under native booleans — normalise both.
        if (is_string($value)) {
            return $value === 't' || $value === 'true' || $value === '1';
        }

        return (bool) $value;
    }
}
