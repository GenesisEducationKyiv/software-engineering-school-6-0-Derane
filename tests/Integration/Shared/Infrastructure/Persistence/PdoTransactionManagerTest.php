<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Persistence;

use App\Shared\Domain\TransactionManager;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Real-Postgres atomicity for the transaction boundary (FR2 mechanism): two writes
 * in one closure, the second throws, neither commits.
 */
final class PdoTransactionManagerTest extends IntegrationTestCase
{
    private TransactionManager $manager;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->c->get(TransactionManager::class);
        $this->pdo = $this->c->get(PDO::class);
    }

    public function testTwoWritesCommitTogetherOnSuccess(): void
    {
        $this->manager->transactional(function (): void {
            $this->insertRepository('alpha/one');
            $this->insertRepository('beta/two');
        });

        $this->assertSame(1, $this->countRepository('alpha/one'));
        $this->assertSame(1, $this->countRepository('beta/two'));
    }

    public function testFirstWriteSucceedsSecondThrowsRollsBackBoth(): void
    {
        try {
            $this->manager->transactional(function (): void {
                $this->insertRepository('alpha/one');
                throw new \RuntimeException('forced failure between writes');
            });
            $this->fail('Expected the forced failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure between writes', $e->getMessage());
        }

        // Neither the first write nor any subsequent one is committed.
        $this->assertSame(0, $this->countRepository('alpha/one'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    private function insertRepository(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT (full_name) DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }

    private function countRepository(string $fullName): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM repositories WHERE full_name = :full_name');
        $stmt->execute(['full_name' => $fullName]);

        return (int) $stmt->fetchColumn();
    }
}
