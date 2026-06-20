<?php

declare(strict_types=1);

namespace App\Sending\Application;

use App\Sending\Domain\WelcomeNotificationKey;

/**
 * The welcome is terminally failed (the ledger carries `terminal_failed_at`).
 * The handler has already re-published the terminal `failed` reply; the consumer
 * must route the message to the DLQ (nack, no requeue) to complete the
 * disposition a prior attempt could not confirm — never re-sending the email.
 */
final class WelcomeAlreadyFailedException extends \RuntimeException
{
    public static function forKey(WelcomeNotificationKey $key): self
    {
        return new self(sprintf(
            'Welcome notification for subscription %d is terminally failed — routing to DLQ',
            $key->subscriptionId,
        ));
    }
}
