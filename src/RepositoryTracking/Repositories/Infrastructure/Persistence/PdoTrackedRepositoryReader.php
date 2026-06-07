<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactoryInterface;
use PDO;

/**
 * PDO read adapter for the `repositories` table. Implements both read-side ports
 * (RepositoryStatusReader + ScanCandidateSource) — per-consumer ISP, one impl.
 * SQL is byte-identical to the legacy TrackedRepositoryReader.
 *
 * @psalm-api
 */
final readonly class PdoTrackedRepositoryReader implements
    RepositoryStatusReader,
    ScanCandidateSource,
    RepositoryCountPort
{
    public function __construct(
        private PDO $pdo,
        private RepositoryStatusFactoryInterface $statusFactory,
    ) {
    }

    #[\Override]
    public function getStatus(string $fullName): ?RepositoryStatus
    {
        $stmt = $this->pdo->prepare('SELECT * FROM repositories WHERE full_name = :full_name');
        $stmt->execute(['full_name' => $fullName]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->statusFactory->fromRow($row) : null;
    }

    #[\Override]
    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM repositories');
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    #[\Override]
    public function countWithReleases(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM repositories WHERE last_seen_tag IS NOT NULL AND last_seen_tag != ''"
        );
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    #[\Override]
    public function getDueForScan(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT full_name
             FROM repositories
             ORDER BY last_checked_at ASC NULLS FIRST, full_name ASC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
