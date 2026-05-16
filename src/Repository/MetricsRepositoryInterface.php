<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MetricsSnapshot;

interface MetricsRepositoryInterface
{
    public function snapshot(): MetricsSnapshot;
}
