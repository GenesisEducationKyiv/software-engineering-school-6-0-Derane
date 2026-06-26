<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\NotificationKey;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\ReleaseTag;
use App\Sending\Domain\RepositoryName;
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
        return new NotificationKey(
            7001,
            new ReleaseTag('v1.0.0-' . bin2hex(random_bytes(4))),
            new RepositoryName('claim/proof-repo'),
        );
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

    public function testRecordFailedAttemptReleasesTheClaimForTheNextClaimer(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->recordFailedAttempt($key, new EmailAddress('a@example.test'), 'SMTP timeout', $claim->token());

        $retry = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertSame(ClaimOutcome::Claimed, $retry->outcome);
        self::assertNotSame($claim->token(), $retry->token(), 'a re-claim must mint a fresh fencing token');
    }

    public function testAttemptCountIncrementsOnEachMutation(): void
    {
        $key = $this->key();

        $failed = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->recordFailedAttempt($key, new EmailAddress('a@example.test'), 'SMTP timeout', $failed->token());

        self::assertSame(
            ['attempt_count' => 1, 'last_error' => 'SMTP timeout'],
            $this->attemptStateFor($key)
        );

        $sent = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->markSent($key, new EmailAddress('a@example.test'), $sent->token());

        self::assertSame(
            ['attempt_count' => 2, 'last_error' => null],
            $this->attemptStateFor($key)
        );
    }

    public function testMarkSentMakesEverySubsequentClaimAnAlreadySentDedupe(): void
    {
        $key = $this->key();

        $claim = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->ledger->markSent($key, new EmailAddress('a@example.test'), $claim->token());

        $repeat = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        self::assertSame(ClaimOutcome::AlreadySent, $repeat->outcome);
        self::assertSame(1, $this->rowCountFor($key));
    }

    public function testAnExpiredLeaseCanBeReclaimedByAnotherWorker(): void
    {
        $key = $this->key();

        $abandoned = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        self::assertSame(ClaimOutcome::Claimed, $abandoned->outcome);

        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);

        $takeover = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        self::assertSame(ClaimOutcome::Claimed, $takeover->outcome);
        self::assertNotSame($abandoned->token(), $takeover->token());
    }

    public function testAStalledWorkersLateMarkSentIsFencedOffAfterATakeover(): void
    {
        $key = $this->key();

        $stalled = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);
        $takeover = $this->ledger->claim($key, new EmailAddress('a@example.test'));

        // The stalled worker resumes and reports late — must be a no-op.
        $this->ledger->markSent($key, new EmailAddress('a@example.test'), $stalled->token());

        self::assertNull($this->sentAtFor($key), 'a fenced markSent must not flip the row to sent');
        self::assertSame(
            ClaimOutcome::InFlight,
            $this->ledger->claim($key, new EmailAddress('b@example.test'))->outcome,
            'the takeover claim must still be live after the fenced write',
        );

        // The legitimate holder's write still applies.
        $this->ledger->markSent($key, new EmailAddress('a@example.test'), $takeover->token());
        self::assertNotNull($this->sentAtFor($key));
    }

    public function testAFencedRecordFailedAttemptDoesNotReleaseTheTakeoverClaim(): void
    {
        $key = $this->key();

        $stalled = $this->ledger->claim($key, new EmailAddress('a@example.test'));
        $this->backdateClaim($key, NotificationLedger::CLAIM_LEASE_SECONDS + 60);
        $this->ledger->claim($key, new EmailAddress('a@example.test'));

        $this->ledger->recordFailedAttempt($key, new EmailAddress('a@example.test'), 'late failure', $stalled->token());

        self::assertSame(
            ClaimOutcome::InFlight,
            $this->ledger->claim($key, new EmailAddress('b@example.test'))->outcome,
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
            ':tag' => $key->tagName->value(),
            ':repo' => $key->repository->value(),
        ]);
        self::assertSame(1, $stmt->rowCount(), 'expected exactly one claim row to backdate');
    }

    private function rowCountFor(NotificationKey $key): int
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName->value(),
            ':repo' => $key->repository->value(),
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function sentAtFor(NotificationKey $key): ?string
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT sent_at FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName->value(),
            ':repo' => $key->repository->value(),
        ]);

        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return array{attempt_count: int, last_error: ?string} */
    private function attemptStateFor(NotificationKey $key): array
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT attempt_count, last_error
             FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName->value(),
            ':repo' => $key->repository->value(),
        ]);

        /** @var array{attempt_count: int|string, last_error: ?string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'expected exactly one notification ledger row');

        return [
            'attempt_count' => (int) $row['attempt_count'],
            'last_error' => $row['last_error'],
        ];
    }
}
