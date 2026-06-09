<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ScanCycleStarted implements ApplicationEvent
{
    public function __construct(
        public int $repositoryCount
    ) {
    }
}
