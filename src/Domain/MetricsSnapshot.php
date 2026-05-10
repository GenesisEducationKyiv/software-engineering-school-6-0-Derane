<?php

declare(strict_types=1);

namespace App\Domain;

final class MetricsSnapshot
{
    public function __construct(
        public readonly int $subscriptions,
        public readonly int $repositories,
        public readonly int $repositoriesWithReleases
    ) {
    }
}
