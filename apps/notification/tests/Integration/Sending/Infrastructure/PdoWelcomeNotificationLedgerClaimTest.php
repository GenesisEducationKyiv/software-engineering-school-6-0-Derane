<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\WelcomeNotificationKey;
use App\Sending\Domain\WelcomeNotificationLedger;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Proves the welcome claim state machine against real Postgres — the parts a PDO
 * mock cannot: the conditional DO UPDATE … WHERE returning no row to the loser,
 * lease-expiry re-claims, claim-token fencing, AlreadySent after markSent, and
 * the HW9-only terminal_failed_at guard that yields AlreadyFailed and blocks any
 * re-claim or re-send (FR6/FR7).
 */
final class PdoWelcomeNotificationLedgerClaimTest extends IntegrationTestCase
{
    private WelcomeNotificationLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->c->get(WelcomeNotificationLedger::class);
    }

    private function key(int $subscriptionId = 9001): WelcomeNotificationKey
    {
        return new WelcomeNotificationKey($subscriptionId);
    }

    public function testSecondClaimerLosesWhileTheFirstClaimIsLive(): void
    {
        $key = $this->key();

        $first = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $second = $this->ledger->claim($key, new EmailAddress('b@example.test'));

        self::assertSame(ClaimOutcome::Claimed, $first->outcome);
        self::assertSame(ClaimOutcome::InFlight, $second->outcome);
        self::assertSame(1, $this->rowCountFor($key), 'the losing claim must not create a second row');
    }

    public function testMarkSentMakesEverySubsequentClaimAnAlreadySentDedupe(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        self::assertTrue($this->ledger->markSent($key, new EmailAddress('a@example.test'), $claim->token()));

        $repeat = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        self::assertSame(ClaimOutcome::AlreadySent, $repeat->outcome);
        self::assertSame(1, $this->rowCountFor($key));
    }

    public function testRecordFailedAttemptReleasesTheClaimForBoundedRetry(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->recordFailedAttempt($key, new EmailAddress('a@example.test'), 'SMTP timeout', $claim->token());

        $retry = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertSame(ClaimOutcome::Claimed, $retry->outcome, 'a non-terminal failure must remain re-claimable');
        self::assertNotSame($claim->token(), $retry->token(), 'a re-claim must mint a fresh fencing token');
    }

    public function testMarkTerminalFailedYieldsAlreadyFailedAndIsNeverReclaimed(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->recordFailedAttempt($key, new EmailAddress('a@example.test'), 'SMTP down', $claim->token());
        $this->ledger->markTerminalFailed($key, 'gave up after 3 retries');

        $afterTerminal = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertSame(ClaimOutcome::AlreadyFailed, $afterTerminal->outcome);
        self::assertNotNull($this->terminalFailedAtFor($key), 'terminal_failed_at must be persisted');
        self::assertNull($this->sentAtFor($key), 'a terminally-failed welcome is never sent');
    }

    public function testTerminalFailureIsIdempotentAndDoesNotClobberTheFirstError(): void
    {
        $key = $this->key();

        $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->markTerminalFailed($key, 'first terminal error');
        $firstStampedAt = $this->terminalFailedAtFor($key);

        // A redelivery re-reaches the terminal branch — must not re-stamp.
        $this->ledger->markTerminalFailed($key, 'second terminal error');

        self::assertSame(
            $firstStampedAt,
            $this->terminalFailedAtFor($key),
            'terminal_failed_at must not be re-stamped',
        );
        self::assertSame('first terminal error', $this->lastErrorFor($key));
    }

    public function testAStalledWorkersLateMarkSentIsFencedOffAfterATakeover(): void
    {
        $key = $this->key();

        $stalled = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->backdateClaim($key, WelcomeNotificationLedger::CLAIM_LEASE_SECONDS + 60);
        $takeover = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertFalse(
            $this->ledger->markSent($key, new EmailAddress('a@example.test'), $stalled->token()),
            'a fenced markSent must report no-op',
        );
        self::assertNull($this->sentAtFor($key), 'a fenced markSent must not flip the row to sent');

        self::assertTrue($this->ledger->markSent($key, new EmailAddress('a@example.test'), $takeover->token()));
        self::assertNotNull($this->sentAtFor($key));
    }

    public function testAnExpiredLeaseCanBeReclaimedByAnotherWorker(): void
    {
        $key = $this->key();

        $abandoned = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->backdateClaim($key, WelcomeNotificationLedger::CLAIM_LEASE_SECONDS + 60);

        $takeover = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertSame(ClaimOutcome::Claimed, $takeover->outcome);
        self::assertNotSame($abandoned->token(), $takeover->token());
    }

    private function backdateClaim(WelcomeNotificationKey $key, int $seconds): void
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'UPDATE welcome_notifications
             SET claimed_at = NOW() - make_interval(secs => :secs)
             WHERE subscription_id = :sub'
        );
        $stmt->execute([':secs' => $seconds, ':sub' => $key->subscriptionId]);
        self::assertSame(1, $stmt->rowCount(), 'expected exactly one claim row to backdate');
    }

    private function rowCountFor(WelcomeNotificationKey $key): int
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM welcome_notifications WHERE subscription_id = :sub'
        );
        $stmt->execute([':sub' => $key->subscriptionId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function sentAtFor(WelcomeNotificationKey $key): ?string
    {
        return $this->columnFor($key, 'sent_at');
    }

    private function terminalFailedAtFor(WelcomeNotificationKey $key): ?string
    {
        return $this->columnFor($key, 'terminal_failed_at');
    }

    private function lastErrorFor(WelcomeNotificationKey $key): ?string
    {
        return $this->columnFor($key, 'last_error');
    }

    private function columnFor(WelcomeNotificationKey $key, string $column): ?string
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            sprintf('SELECT %s FROM welcome_notifications WHERE subscription_id = :sub', $column)
        );
        $stmt->execute([':sub' => $key->subscriptionId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
