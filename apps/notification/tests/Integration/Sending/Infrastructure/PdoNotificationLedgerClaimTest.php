<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\NotificationKey;
use App\Sending\Domain\NotificationLedger;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Proves the claim state machine against real Postgres — the parts a PDO
 * mock cannot: the conditional DO UPDATE … WHERE returning no row to the
 * loser, lease-expiry re-claims, claim release on failure, AlreadySent after
 * markSent, and claim-token fencing of late writers.
 */
final class PdoNotificationLedgerClaimTest extends IntegrationTestCase
{
    private NotificationLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->c->get(NotificationLedger::class);
    }

    private function key(): NotificationKey
    {
        return new NotificationKey(7001, 'v1.0.0-' . bin2hex(random_bytes(4)), 'claim/proof-repo');
    }

    public function testSecondClaimerLosesWhileTheFirstClaimIsLive(): void
    {
        $key = $this->key();

        $first = $this->ledger->claim($key, 'a@example.test');
        $second = $this->ledger->claim($key, 'b@example.test');

        self::assertSame(ClaimOutcome::Claimed, $first->outcome);
        self::assertSame(ClaimOutcome::InFlight, $second->outcome);
        self::assertSame(1, $this->rowCountFor($key), 'the losing claim must not create a second row');
    }

    public function testRecordFailedAttemptReleasesTheClaimForTheNextClaimer(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, 'a@example.test');
        $this->ledger->recordFailedAttempt($key, 'a@example.test', 'SMTP timeout', $claim->token());

        $retry = $this->ledger->claim($key, 'a@example.test');

        self::assertSame(ClaimOutcome::Claimed, $retry->outcome);
        self::assertNotSame($claim->token(), $retry->token(), 'a re-claim must mint a fresh fencing token');
    }

    public function testMarkSentMakesEverySubsequentClaimAnAlreadySentDedupe(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, 'a@example.test');
        $this->ledger->markSent($key, 'a@example.test', $claim->token());

        self::assertSame(ClaimOutcome::AlreadySent, $this->ledger->claim($key, 'a@example.test')->outcome);
        self::assertSame(1, $this->rowCountFor($key));
    }

    public function testAnExpiredLeaseCanBeReclaimedByAnotherWorker(): void
    {
        $key = $this->key();

        $abandoned = $this->ledger->claim($key, 'a@example.test');
        self::assertSame(ClaimOutcome::Claimed, $abandoned->outcome);

        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);

        $takeover = $this->ledger->claim($key, 'a@example.test');

        self::assertSame(ClaimOutcome::Claimed, $takeover->outcome);
        self::assertNotSame($abandoned->token(), $takeover->token());
    }

    public function testAStalledWorkersLateMarkSentIsFencedOffAfterATakeover(): void
    {
        $key = $this->key();

        $stalled = $this->ledger->claim($key, 'a@example.test');
        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);
        $takeover = $this->ledger->claim($key, 'a@example.test');

        // The stalled worker resumes and reports late — must be a no-op.
        $this->ledger->markSent($key, 'a@example.test', $stalled->token());

        self::assertNull($this->sentAtFor($key), 'a fenced markSent must not flip the row to sent');
        self::assertSame(
            ClaimOutcome::InFlight,
            $this->ledger->claim($key, 'b@example.test')->outcome,
            'the takeover claim must still be live after the fenced write',
        );

        // The legitimate holder's write still applies.
        $this->ledger->markSent($key, 'a@example.test', $takeover->token());
        self::assertNotNull($this->sentAtFor($key));
    }

    public function testAFencedRecordFailedAttemptDoesNotReleaseTheTakeoverClaim(): void
    {
        $key = $this->key();

        $stalled = $this->ledger->claim($key, 'a@example.test');
        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);
        $this->ledger->claim($key, 'a@example.test');

        $this->ledger->recordFailedAttempt($key, 'a@example.test', 'late failure', $stalled->token());

        self::assertSame(
            ClaimOutcome::InFlight,
            $this->ledger->claim($key, 'b@example.test')->outcome,
            'a fenced failure report must not free the live claim',
        );
    }

    private function backdateClaim(NotificationKey $key, int $seconds): void
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            "UPDATE release_notifications
             SET claimed_at = NOW() - make_interval(secs => :secs)
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo"
        );
        $stmt->execute([
            ':secs' => $seconds,
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName,
            ':repo' => $key->repository,
        ]);
        self::assertSame(1, $stmt->rowCount(), 'expected exactly one claim row to backdate');
    }

    private function rowCountFor(NotificationKey $key): int
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([':sub' => $key->subscriptionId, ':tag' => $key->tagName, ':repo' => $key->repository]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function sentAtFor(NotificationKey $key): ?string
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT sent_at FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([':sub' => $key->subscriptionId, ':tag' => $key->tagName, ':repo' => $key->repository]);

        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
