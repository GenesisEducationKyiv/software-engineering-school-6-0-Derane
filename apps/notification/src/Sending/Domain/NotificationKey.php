<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * The business identity of one release notification — the dedup key. Wire
 * eventId is deliberately NOT part of it: dedup is by business key, so a
 * re-published release (new eventId, same subscription/tag/repository) still
 * counts as the same notification.
 */
final readonly class NotificationKey
{
    public function __construct(
        public int $subscriptionId,
        public string $tagName,
        public string $repository,
    ) {
    }
}
