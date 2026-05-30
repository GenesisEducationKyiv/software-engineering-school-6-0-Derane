<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ScanCycleFailed implements ApplicationEvent
{
    public function __construct(
        public string $errorClass,
        public string $errorMessage
    ) {
    }
}
