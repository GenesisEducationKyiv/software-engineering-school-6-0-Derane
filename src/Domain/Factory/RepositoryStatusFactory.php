<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\RepositoryStatus;

/** @psalm-api */
final class RepositoryStatusFactory implements RepositoryStatusFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): RepositoryStatus
    {
        return new RepositoryStatus(
            (string) ($row['full_name'] ?? ''),
            isset($row['last_seen_tag']) ? (string) $row['last_seen_tag'] : null,
            isset($row['last_checked_at']) ? (string) $row['last_checked_at'] : null,
        );
    }
}
