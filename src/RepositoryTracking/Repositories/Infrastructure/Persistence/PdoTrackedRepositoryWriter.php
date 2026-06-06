<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use PDO;

/**
 * PDO write adapter for the `repositories` table. Implements both write-side
 * ports (TrackedRepositoryRegistrar + ScanProgressWriter) — per-consumer ISP,
 * one impl. SQL is byte-identical to the legacy TrackedRepositoryWriter.
 *
 * @psalm-api
 */
final readonly class PdoTrackedRepositoryWriter implements TrackedRepositoryRegistrar, ScanProgressWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function ensureExists(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT (full_name) DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }

    #[\Override]
    public function markChecked(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['repository' => $fullName]);
    }

    #[\Override]
    public function markReleaseSeen(string $fullName, string $tag): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_seen_tag = :tag, last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['tag' => $tag, 'repository' => $fullName]);
    }
}
