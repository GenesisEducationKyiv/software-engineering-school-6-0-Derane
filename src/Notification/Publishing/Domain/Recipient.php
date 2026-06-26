<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;

/**
 * This context's own model of a release-email recipient. Deliberately separate
 * from Subscription\Subscriptions\Domain\SubscriberRef so the Publishing
 * use-case speaks only its own ubiquitous language; the translation from the
 * Subscription context happens once, inside the ACL adapter that implements
 * SubscriberProvider.
 *
 * @psalm-api
 */
final readonly class Recipient
{
    public function __construct(
        public int $subscriptionId,
        public EmailAddress $email,
    ) {
    }
}
