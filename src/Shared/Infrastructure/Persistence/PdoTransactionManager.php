<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Domain\TransactionManager;
use PDO;

/**
 * The single explicit DB-transaction boundary over the shared PDO::class.
 *
 * transactional() runs $work inside beginTransaction() -> commit(); on any throw
 * it rollBack()s and rethrows, so a partial write is never committed. Both the
 * atomic saga start (Subscription write path) and the reply orchestrator (Saga
 * side) depend on the TransactionManager Domain port, never on a raw PDO, so no
 * Application layer reaches into Shared.Infrastructure.
 *
 * Nested-call safe: if a transaction is already open (inTransaction()), $work is
 * run on the enclosing transaction without opening/committing/rolling back a
 * second one — so a wrapping transactional() keeps full control of the boundary.
 *
 * @psalm-api
 */
final readonly class PdoTransactionManager implements TransactionManager
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function transactional(callable $work): mixed
    {
        // Re-entrancy guard: an already-open transaction means we are nested
        // inside an outer transactional() — just run the work and let the
        // outermost call own the commit/rollback so the two writes stay atomic.
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
