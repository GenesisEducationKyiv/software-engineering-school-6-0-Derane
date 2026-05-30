<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class SubscriptionDeleted implements ApplicationEvent
{
    public function __construct(
        public int $id
    ) {
    }
}
