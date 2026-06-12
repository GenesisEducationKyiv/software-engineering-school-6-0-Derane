<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Persistence;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\NotificationKey;
use App\Sending\Infrastructure\Persistence\PdoNotificationLedger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mock-level checks of the SQL shape and parameter plumbing only. The actual
 * claim state machine (atomic loser-gets-nothing upsert, lease expiry,
 * fencing) is proven against real Postgres in
 * Tests\Integration\Sending\Infrastructure\PdoNotificationLedgerClaimTest.
 */
final class PdoNotificationLedgerTest extends TestCase
{
    /** @var \PDO&MockObject */
    private \PDO $pdo;
    /** @var \PDOStatement&MockObject */
    private \PDOStatement $stmt;
    private PdoNotificationLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = $this->createMock(\PDO::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->ledger = new PdoNotificationLedger($this->pdo);
    }

    private function key(): NotificationKey
    {
        return new NotificationKey(42, 'v2.0.0', 'acme/app');
    }

    public function testClaimReturnsClaimedWithTheStoredFencingTokenWhenTheUpsertReturnsARow(): void
    {
        /** @var array<string, mixed> $executedParams */
        $executedParams = [];
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with(self::logicalAnd(
                self::stringContains('ON CONFLICT'),
                self::stringContains('RETURNING id'),
            ))
            ->willReturn($this->stmt);
        $this->stmt->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$executedParams): bool {
                $executedParams = $params;
                return true;
            });
        $this->stmt->method('fetchColumn')->willReturn('7');

        $result = $this->ledger->claim($this->key(), 'alice@example.com');

        self::assertSame(ClaimOutcome::Claimed, $result->outcome);
        self::assertSame(42, $executedParams[':sub']);
        self::assertSame('v2.0.0', $executedParams[':tag']);
        self::assertSame('acme/app', $executedParams[':repo']);
        self::assertSame('alice@example.com', $executedParams[':email']);
        // The token handed back to the caller is exactly the one persisted.
        self::assertSame($executedParams[':token'], $result->token());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->token());
    }

    public function testClaimReturnsAlreadySentWhenNoRowIsClaimableAndTheNotificationWasSent(): void
    {
        $claimStmt = $this->createMock(\PDOStatement::class);
        $claimStmt->method('execute')->willReturn(true);
        $claimStmt->method('fetchColumn')->willReturn(false);

        $sentStmt = $this->createMock(\PDOStatement::class);
        $sentStmt->method('execute')->willReturn(true);
        $sentStmt->method('fetchColumn')->willReturn('1');

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($claimStmt, $sentStmt);

        $result = $this->ledger->claim($this->key(), 'alice@example.com');

        self::assertSame(ClaimOutcome::AlreadySent, $result->outcome);
    }

    public function testClaimReturnsInFlightWhenNoRowIsClaimableAndTheNotificationIsUnsent(): void
    {
        $claimStmt = $this->createMock(\PDOStatement::class);
        $claimStmt->method('execute')->willReturn(true);
        $claimStmt->method('fetchColumn')->willReturn(false);

        $sentStmt = $this->createMock(\PDOStatement::class);
        $sentStmt->method('execute')->willReturn(true);
        $sentStmt->method('fetchColumn')->willReturn('0');

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($claimStmt, $sentStmt);

        $result = $this->ledger->claim($this->key(), 'alice@example.com');

        self::assertSame(ClaimOutcome::InFlight, $result->outcome);
        $this->expectException(\LogicException::class);
        $result->token();
    }

    public function testClaimOnlyUpdatesUnsentRowsWhoseClaimIsFreeOrExpired(): void
    {
        $capturedSql = '';
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $this->stmt;
            });
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchColumn')->willReturn('7');

        $this->ledger->claim($this->key(), 'alice@example.com');

        self::assertStringContainsString('sent_at IS NULL', $capturedSql);
        self::assertStringContainsString('claimed_at IS NULL', $capturedSql);
        self::assertStringContainsString("INTERVAL '300 seconds'", $capturedSql);
        self::assertStringContainsString('claim_token = excluded.claim_token', $capturedSql);
    }

    public function testMarkSentIsFencedOnTheClaimTokenAndReleasesTheClaim(): void
    {
        $capturedSql = '';
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $this->stmt;
            });
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([
                ':sub' => 42,
                ':tag' => 'v2.0.0',
                ':repo' => 'acme/app',
                ':email' => 'alice@example.com',
                ':token' => 'fence-token',
            ])
            ->willReturn(true);

        $this->ledger->markSent($this->key(), 'alice@example.com', 'fence-token');

        self::assertStringContainsString('sent_at = NOW()', $capturedSql);
        self::assertStringContainsString('claimed_at = NULL', $capturedSql);
        self::assertStringContainsString('claim_token = NULL', $capturedSql);
        self::assertStringContainsString('attempt_count = attempt_count + 1', $capturedSql);
        self::assertStringNotContainsString('attempts = attempts + 1', $capturedSql);
        self::assertStringContainsString('updated_at = NOW()', $capturedSql);
        self::assertStringContainsString('AND claim_token = :token', $capturedSql);
    }

    public function testRecordFailedAttemptIsFencedAndReleasesTheClaimWithoutSettingSentAt(): void
    {
        $capturedSql = '';
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $this->stmt;
            });
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([
                ':sub'   => 42,
                ':tag'   => 'v2.0.0',
                ':repo'  => 'acme/app',
                ':email' => 'alice@example.com',
                ':error' => 'SMTP timeout',
                ':token' => 'fence-token',
            ])
            ->willReturn(true);

        $this->ledger->recordFailedAttempt($this->key(), 'alice@example.com', 'SMTP timeout', 'fence-token');

        self::assertStringContainsString('claimed_at = NULL', $capturedSql);
        self::assertStringContainsString('claim_token = NULL', $capturedSql);
        self::assertStringContainsString('attempt_count = attempt_count + 1', $capturedSql);
        self::assertStringNotContainsString('attempts = attempts + 1', $capturedSql);
        self::assertStringContainsString('updated_at = NOW()', $capturedSql);
        self::assertStringContainsString('AND claim_token = :token', $capturedSql);
        self::assertStringNotContainsString('sent_at = NOW()', $capturedSql);
    }
}
