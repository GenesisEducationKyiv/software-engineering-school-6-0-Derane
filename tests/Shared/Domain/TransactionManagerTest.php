<?php

declare(strict_types=1);

namespace Tests\Shared\Domain;

use App\Shared\Domain\TransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * The port contract: transactional() runs the work and returns its result; a
 * throw inside the work propagates. The concrete PdoTransactionManager (and its
 * rollback semantics) is tested in Epic B; here we pin the port's behavioural
 * contract against a trivial in-memory implementation so consumers can rely on it.
 */
final class TransactionManagerTest extends TestCase
{
    private function passthroughManager(): TransactionManager
    {
        return new class implements TransactionManager {
            #[\Override]
            public function transactional(callable $work): mixed
            {
                return $work();
            }
        };
    }

    public function testRunsTheWorkAndReturnsItsResult(): void
    {
        $manager = $this->passthroughManager();

        $result = $manager->transactional(static fn (): int => 42);

        $this->assertSame(42, $result);
    }

    public function testThrowFromWorkPropagates(): void
    {
        $manager = $this->passthroughManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $manager->transactional(static function (): void {
            throw new \RuntimeException('boom');
        });
    }
}
