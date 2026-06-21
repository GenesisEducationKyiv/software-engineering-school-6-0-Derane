<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Persistence;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\WelcomeNotificationKey;
use App\Sending\Infrastructure\Persistence\PdoWelcomeNotificationLedger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mock-level checks of the SQL shape and parameter plumbing only. The full claim
 * state machine (atomic loser-gets-nothing upsert, lease expiry, fencing, the
 * terminal_failed_at guard) is proven against real Postgres in
 * Tests\Integration\Sending\Infrastructure\PdoWelcomeNotificationLedgerClaimTest.
 */
final class PdoWelcomeNotificationLedgerTest extends TestCase
{
    /** @var \PDO&MockObject */
    private \PDO $pdo;
    /** @var \PDOStatement&MockObject */
    private \PDOStatement $stmt;
    private PdoWelcomeNotificationLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = $this->createMock(\PDO::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->ledger = new PdoWelcomeNotificationLedger($this->pdo);
    }

    private function key(): WelcomeNotificationKey
    {
        return new WelcomeNotificationKey(42);
    }

    public function testClaimIsKeyedOnSubscriptionIdAloneAndGuardsTerminalFailure(): void
    {
        $capturedSql = '';
        /** @var array<string, mixed> $executedParams */
        $executedParams = [];
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $this->stmt;
            });
        $this->stmt->method('execute')
            ->willReturnCallback(function (array $params) use (&$executedParams): bool {
                $executedParams = $params;
                return true;
            });
        $this->stmt->method('fetchColumn')->willReturn('7');

        $result = $this->ledger->claim($this->key(), new EmailAddress('alice@example.com'));

        self::assertSame(ClaimOutcome::Claimed, $result->outcome);
        self::assertSame($executedParams[':token'], $result->token());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->token());

        self::assertStringContainsString('ON CONFLICT (subscription_id)', $capturedSql);
        self::assertStringContainsString('RETURNING id', $capturedSql);
        self::assertStringContainsString('sent_at IS NULL', $capturedSql);
        self::assertStringContainsString('terminal_failed_at IS NULL', $capturedSql);
        self::assertStringContainsString("INTERVAL '300 seconds'", $capturedSql);
        self::assertStringNotContainsString('tag_name', $capturedSql);
        self::assertStringNotContainsString('repository', $capturedSql);
    }

    public function testClaimReturnsAlreadySentWhenTheRowIsSent(): void
    {
        $this->ledger = new PdoWelcomeNotificationLedger($this->pdo);

        $claimStmt = $this->createMock(\PDOStatement::class);
        $claimStmt->method('execute')->willReturn(true);
        $claimStmt->method('fetchColumn')->willReturn(false);

        $stateStmt = $this->createMock(\PDOStatement::class);
        $stateStmt->method('execute')->willReturn(true);
        $stateStmt->method('fetch')->willReturn(['sent' => 't', 'terminal' => 'f']);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($claimStmt, $stateStmt);

        $result = $this->ledger->claim($this->key(), new EmailAddress('alice@example.com'));

        self::assertSame(ClaimOutcome::AlreadySent, $result->outcome);
    }

    public function testClaimReturnsAlreadyFailedWhenTheRowIsTerminallyFailed(): void
    {
        $claimStmt = $this->createMock(\PDOStatement::class);
        $claimStmt->method('execute')->willReturn(true);
        $claimStmt->method('fetchColumn')->willReturn(false);

        $stateStmt = $this->createMock(\PDOStatement::class);
        $stateStmt->method('execute')->willReturn(true);
        // Postgres returns boolean IS NOT NULL as 't'/'f' strings — the ledger
        // must NOT treat the string 'f' as truthy.
        $stateStmt->method('fetch')->willReturn(['sent' => 'f', 'terminal' => 't']);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($claimStmt, $stateStmt);

        $result = $this->ledger->claim($this->key(), new EmailAddress('alice@example.com'));

        self::assertSame(ClaimOutcome::AlreadyFailed, $result->outcome);
    }

    public function testClaimReturnsInFlightWhenTheRowIsNeitherSentNorTerminal(): void
    {
        $claimStmt = $this->createMock(\PDOStatement::class);
        $claimStmt->method('execute')->willReturn(true);
        $claimStmt->method('fetchColumn')->willReturn(false);

        $stateStmt = $this->createMock(\PDOStatement::class);
        $stateStmt->method('execute')->willReturn(true);
        $stateStmt->method('fetch')->willReturn(['sent' => 'f', 'terminal' => 'f']);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($claimStmt, $stateStmt);

        $result = $this->ledger->claim($this->key(), new EmailAddress('alice@example.com'));

        self::assertSame(ClaimOutcome::InFlight, $result->outcome);
    }

    public function testMarkSentIsFencedOnTheClaimTokenKeyedOnSubscriptionId(): void
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
                ':email' => 'alice@example.com',
                ':token' => 'fence-token',
            ])
            ->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        self::assertTrue(
            $this->ledger->markSent($this->key(), new EmailAddress('alice@example.com'), 'fence-token'),
        );

        self::assertStringContainsString('sent_at = NOW()', $capturedSql);
        self::assertStringContainsString('claimed_at = NULL', $capturedSql);
        self::assertStringContainsString('AND claim_token = :token', $capturedSql);
    }

    public function testMarkTerminalFailedStampsTerminalFailedAtGuardingAgainstReStamp(): void
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
                ':error' => 'gave up after 3 retries',
            ])
            ->willReturn(true);

        $this->ledger->markTerminalFailed($this->key(), 'gave up after 3 retries');

        self::assertStringContainsString('terminal_failed_at = NOW()', $capturedSql);
        self::assertStringContainsString('claimed_at = NULL', $capturedSql);
        // Idempotent stamp: only fires on a not-yet-terminal, not-yet-sent row.
        self::assertStringContainsString('sent_at IS NULL', $capturedSql);
        self::assertStringContainsString('terminal_failed_at IS NULL', $capturedSql);
    }

    public function testRecordFailedAttemptReleasesTheClaimWithoutSettingTerminalOrSent(): void
    {
        $capturedSql = '';
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $this->stmt;
            });
        $this->stmt->method('execute')->willReturn(true);

        $this->ledger->recordFailedAttempt(
            $this->key(),
            new EmailAddress('alice@example.com'),
            'SMTP timeout',
            'fence-token',
        );

        self::assertStringContainsString('claimed_at = NULL', $capturedSql);
        self::assertStringContainsString('attempt_count = attempt_count + 1', $capturedSql);
        self::assertStringContainsString('AND claim_token = :token', $capturedSql);
        self::assertStringNotContainsString('sent_at = NOW()', $capturedSql);
        self::assertStringNotContainsString('terminal_failed_at = NOW()', $capturedSql);
    }
}
