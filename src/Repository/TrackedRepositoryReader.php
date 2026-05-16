<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Factory\RepositoryStatusFactoryInterface;
use App\Domain\RepositoryStatus;
use PDO;

/** @psalm-api */
final readonly class TrackedRepositoryReader implements
    RepositoryStatusReader,
    ScanCandidateSource
{
    public function __construct(
        private PDO $pdo,
        private RepositoryStatusFactoryInterface $statusFactory
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
