<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

final class SubscriptionNotFoundException extends \DomainException
{
    public function __construct(int $id)
    {
        parent::__construct("Subscription #{$id} not found");
    }
}
