<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ReleaseNotificationFailed implements ApplicationEvent
{
    public function __construct(
        public string $email,
        public string $repository,
        public string $errorClass,
        public string $errorMessage
    ) {
    }
}
