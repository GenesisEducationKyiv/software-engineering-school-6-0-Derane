<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Health;

final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    /** @param \Closure(): \PDO $connect lazily resolves the connection so a cold DB is probed inside check() */
    public function __construct(private \Closure $connect)
    {
    }

    #[\Override]
    public function check(): void
    {
        try {
            ($this->connect)()->query('SELECT 1');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database is unreachable', 0, $e);
        }
    }
}
