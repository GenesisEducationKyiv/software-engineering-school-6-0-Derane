<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\SubscriberRef;

interface SubscriberRefFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): SubscriberRef;
}
