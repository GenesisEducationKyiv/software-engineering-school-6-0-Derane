<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\Subscription;

interface SubscriptionFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): Subscription;
}
