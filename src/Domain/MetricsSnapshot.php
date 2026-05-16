<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class MetricsSnapshot
{
    public function __construct(
        public int $subscriptions,
        public int $repositories,
        public int $repositoriesWithReleases
    ) {
    }
}
