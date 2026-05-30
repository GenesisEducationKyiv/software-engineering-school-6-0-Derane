<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MetricsSnapshot;

/**
 * Only consumer is the lazy snapshot provider wired in config/container.php (which
 * Psalm does not scan), so mark the contract as API to keep cold `psalm --no-cache`
 * runs from flagging {@see self::snapshot()} as unused.
 *
 * @psalm-api
 */
interface MetricsRepositoryInterface
{
    public function snapshot(): MetricsSnapshot;
}
