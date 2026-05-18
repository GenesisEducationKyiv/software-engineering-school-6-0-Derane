<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** @psalm-api */
final readonly class TrackedRepositoryWriter implements
    TrackedRepositoryRegistrar,
    ScanProgressWriter
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
