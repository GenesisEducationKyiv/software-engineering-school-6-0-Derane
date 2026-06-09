<?php

declare(strict_types=1);

namespace App\Application\Event\Factory;

use App\Application\Event\SubscriptionCreated;
use App\Application\Event\SubscriptionDeleted;

/**
 * Builds subscription lifecycle events. Injected into
 * {@see \App\Service\SubscriptionService}.
 */
interface SubscriptionEventFactoryInterface
{
    public function subscriptionCreated(string $email, string $repository): SubscriptionCreated;

    public function subscriptionDeleted(int $id): SubscriptionDeleted;
}
