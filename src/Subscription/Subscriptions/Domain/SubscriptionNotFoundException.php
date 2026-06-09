<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

final class SubscriptionNotFoundException extends \DomainException
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
