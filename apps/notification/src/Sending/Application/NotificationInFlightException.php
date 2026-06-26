<?php

declare(strict_types=1);

namespace App\Sending\Application;

use App\Sending\Domain\NotificationKey;

/**
 * Another worker holds a live claim on this notification. Environmental and
 * transient by definition — the consumer's retry path handles it: a later
 * redelivery either finds the ledger row sent (dedupe) or wins the claim.
 */
final class NotificationInFlightException extends \RuntimeException
{
    public static function forKey(NotificationKey $key): self
    {
        return new self(sprintf(
            'Notification for subscription %d, tag %s, repository %s is already in flight',
            $key->subscriptionId,
            $key->tagName,
            $key->repository,
        ));
    }
}
