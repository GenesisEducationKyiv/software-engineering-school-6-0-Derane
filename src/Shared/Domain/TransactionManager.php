<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Port for the one explicit DB transaction boundary. Runs $work inside a single
 * transaction (begin -> work -> commit, rollback + rethrow on any throw) and
 * returns whatever $work returns.
 *
 * Both the atomic saga start (Subscription write path) and the reply orchestrator
 * (Saga side) depend on this Domain port instead of a raw PDO, so no
 * Application layer reaches into Shared.Infrastructure. The concrete
 * PdoTransactionManager wraps the single shared PDO::class, wired at the
 * composition root.
 *
 * @psalm-api
 */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;
}
