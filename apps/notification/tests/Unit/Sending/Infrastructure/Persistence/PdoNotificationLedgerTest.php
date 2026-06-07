<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Persistence;

use App\Sending\Infrastructure\Persistence\PdoNotificationLedger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    public function testHasBeenSentReturnsFalseWhenNoRecord(): void
    {
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchColumn')->willReturn('0');

        self::assertFalse($this->ledger->hasBeenSent(1, 'v1.0.0', 'owner/repo'));
    }

    public function testHasBeenSentReturnsTrueWhenRecordExists(): void
    {
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchColumn')->willReturn('1');

        self::assertTrue($this->ledger->hasBeenSent(1, 'v1.0.0', 'owner/repo'));
    }

    public function testMarkSentExecutesInsertWithCorrectParameters(): void
    {
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([':sub' => 42, ':tag' => 'v2.0.0', ':repo' => 'acme/app', ':email' => 'alice@example.com'])
            ->willReturn(true);

        $this->ledger->markSent(42, 'v2.0.0', 'acme/app', 'alice@example.com');
    }

    public function testMarkSentIsIdempotentViaOnConflictClause(): void
    {
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('ON CONFLICT'))
            ->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);

        $this->ledger->markSent(1, 'v1.0.0', 'owner/repo', 'user@example.com');
    }
}
