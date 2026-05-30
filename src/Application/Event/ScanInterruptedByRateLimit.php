<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ScanInterruptedByRateLimit implements ApplicationEvent
{
    public function __construct(
        public string $repository,
        public string $retryAfter
    ) {
    }
}
