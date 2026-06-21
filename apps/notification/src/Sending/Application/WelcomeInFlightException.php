<?php

declare(strict_types=1);

namespace App\Sending\Application;

use App\Sending\Domain\WelcomeNotificationKey;

/**
 * Another worker holds a live claim on this welcome. Benign contention — the
 * consumer parks the message for the claim lease without consuming retry budget;
 * a later redelivery either dedups (AlreadySent) or wins the claim.
 */
final class WelcomeInFlightException extends \RuntimeException
{
    public static function forKey(WelcomeNotificationKey $key): self
    {
        return new self(sprintf(
            'Welcome notification for subscription %d is already in flight',
            $key->subscriptionId,
        ));
    }
}
