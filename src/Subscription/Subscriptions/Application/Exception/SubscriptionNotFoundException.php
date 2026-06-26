<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Exception;

/**
 * Application-level failure: a use-case looked up a subscription that does not
 * exist. Not a domain exception — domain exceptions express model invariant
 * violations (a value out of range, a forbidden state transition); "not found"
 * is a use-case outcome, so it lives in the Application layer and extends
 * \RuntimeException.
 */
final class SubscriptionNotFoundException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(int $id): self
    {
        return new self("Subscription #{$id} not found");
    }

    public static function forEmailAndRepository(string $email, string $repository): self
    {
        return new self("Subscription for {$email} on {$repository} not found");
    }
}
