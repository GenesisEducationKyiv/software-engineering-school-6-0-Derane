<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class SubscriptionCreated implements ApplicationEvent
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}
