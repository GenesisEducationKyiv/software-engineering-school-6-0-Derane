<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Persistence;

use App\Shared\Infrastructure\Persistence\PdoTransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Env-independent unit coverage of the transaction-boundary semantics: commit on
 * success, rollback + rethrow on a throw, and the nested-call guard. A spy PDO
 * records begin/commit/rollBack ordering and models inTransaction() so the SQL
 * driver is not needed here (real atomicity is covered in the Integration suite).
 */
final class PdoTransactionManagerTest extends TestCase
{
    public function testRunsWorkAndCommitsReturningTheResult(): void
    {
        $pdo = $this->spyPdo();
        $manager = new PdoTransactionManager($pdo);

        $result = $manager->transactional(static fn (): int => 42);

        $this->assertSame(42, $result);
        $this->assertSame(['begin', 'commit'], $pdo->calls);
    }

    public function testRollsBackAndRethrowsOnThrow(): void
    {
        $pdo = $this->spyPdo();
        $manager = new PdoTransactionManager($pdo);

        try {
            $manager->transactional(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['begin', 'rollBack'], $pdo->calls);
    }

    public function testNestedCallDoesNotOpenOrCommitASecondTransaction(): void
    {
        $pdo = $this->spyPdo();
        $manager = new PdoTransactionManager($pdo);

        $result = $manager->transactional(static function () use ($manager): string {
            // A second transactional() while one is already open must NOT begin or
            // commit again — the outer call owns the boundary.
            return $manager->transactional(static fn (): string => 'inner');
        });

        $this->assertSame('inner', $result);
        $this->assertSame(['begin', 'commit'], $pdo->calls);
    }

    /**
     * @return PDO&object{calls: list<string>}
     */
    private function spyPdo(): PDO
    {
        return new class extends PDO {
            /** @var list<string> */
            public array $calls = [];

            private bool $inTx = false;

            public function __construct()
            {
                // Deliberately do NOT call parent::__construct — no real driver.
            }

            #[\Override]
            public function beginTransaction(): bool
            {
                $this->calls[] = 'begin';
                $this->inTx = true;

                return true;
            }

            #[\Override]
            public function commit(): bool
            {
                $this->calls[] = 'commit';
                $this->inTx = false;

                return true;
            }

            #[\Override]
            public function rollBack(): bool
            {
                $this->calls[] = 'rollBack';
                $this->inTx = false;

                return true;
            }

            #[\Override]
            public function inTransaction(): bool
            {
                return $this->inTx;
            }
        };
    }
}
