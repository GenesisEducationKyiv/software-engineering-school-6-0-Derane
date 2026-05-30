<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ReleaseNotificationSent implements ApplicationEvent
{
    public function __construct(
        public string $email,
        public string $repository,
        public ?string $tag
    ) {
    }
}
