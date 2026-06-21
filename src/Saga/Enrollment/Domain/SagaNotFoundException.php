<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/** @psalm-api */
final class SagaNotFoundException extends \DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withSagaId(string $sagaId): self
    {
        return new self("Enrollment saga {$sagaId} not found");
    }

    public static function forSubscriptionId(int $subscriptionId): self
    {
        return new self("Enrollment saga for subscription #{$subscriptionId} not found");
    }
}
