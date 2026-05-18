<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MetricsSnapshot;
use PDO;

/** @psalm-api */
final readonly class MetricsRepository implements MetricsRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function snapshot(): MetricsSnapshot
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM subscriptions');
        $subscriptions = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM repositories');
        $repositories = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM repositories WHERE last_seen_tag IS NOT NULL AND last_seen_tag != ''"
        );
        $withReleases = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        return new MetricsSnapshot($subscriptions, $repositories, $withReleases);
    }
}
