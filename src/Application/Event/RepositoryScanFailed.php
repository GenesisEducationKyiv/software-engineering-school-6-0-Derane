<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class RepositoryScanFailed implements ApplicationEvent
{
    public function __construct(
        public string $repository,
        public string $errorClass,
        public string $errorMessage
    ) {
    }
}
