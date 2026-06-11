<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Health;

use App\Sending\Infrastructure\Health\DatabaseHealthCheck;
use PHPUnit\Framework\TestCase;

final class DatabaseHealthCheckTest extends TestCase
{
    public function testCheckPassesWhenPdoQuerySucceeds(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('query')
            ->with('SELECT 1')
            ->willReturn($this->createMock(\PDOStatement::class));

        $check = new DatabaseHealthCheck(static fn(): \PDO => $pdo);

        // No exception means the check passed.
        $check->check();
        $this->addToAssertionCount(1);
    }

    public function testCheckThrowsRuntimeExceptionWhenPdoQueryFails(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')
            ->willThrowException(new \PDOException('Connection refused'));

        $check = new DatabaseHealthCheck(static fn(): \PDO => $pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database is unreachable');

        $check->check();
    }

    public function testCheckThrowsRuntimeExceptionWhenTheConnectionItselfCannotBeOpened(): void
    {
        // Cold-DB-down: the connection is resolved lazily inside check(), so a
        // constructor-time PDOException is caught here and mapped to the 503 path
        // instead of escaping during container resolution.
        $check = new DatabaseHealthCheck(static function (): \PDO {
            throw new \PDOException('could not connect to server');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database is unreachable');

        $check->check();
    }
}
