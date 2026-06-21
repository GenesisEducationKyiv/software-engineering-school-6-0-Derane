<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * The business identity of one welcome notification — the dedup key. Keyed by
 * `subscriptionId` ALONE (one welcome per subscription, FR6), unlike the release
 * ledger's `(subscriptionId, repository, tagName)` key. A re-relayed or
 * redelivered SendWelcomeEmail for the same subscription is the same welcome.
 */
final readonly class WelcomeNotificationKey
{
    public function __construct(
        public int $subscriptionId,
    ) {
    }
}
