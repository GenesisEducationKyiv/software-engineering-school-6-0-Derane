<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

/**
 * Cross-context Domain port: resolves the subscribers of a repository for the
 * notification flow. A legitimate Domain port edge consumed by the (legacy until
 * C1) NotificationDispatcher.
 *
 * @psalm-api
 */
interface SubscriberFinder
{
    public function findSubscribersByRepository(string $repository): SubscriberCollection;
}
