<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Health;

final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private string $host,
        private string $port,
        private string $name,
        private string $user,
        private string $password,
    ) {
    }

    #[\Override]
    public function check(): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $this->host, $this->port, $this->name);
        $pdo = new \PDO($dsn, $this->user, $this->password);
        $pdo->query('SELECT 1');
    }
}
