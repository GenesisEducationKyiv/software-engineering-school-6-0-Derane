<?php

declare(strict_types=1);

namespace App\Health;

use PDO;

/** @psalm-api */
final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function check(): void
    {
        $this->pdo->query('SELECT 1');
    }
}
