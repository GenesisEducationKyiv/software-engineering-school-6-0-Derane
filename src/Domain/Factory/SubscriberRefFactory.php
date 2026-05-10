<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\SubscriberRef;

/** @psalm-api */
final class SubscriberRefFactory implements SubscriberRefFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): SubscriberRef
    {
        return new SubscriberRef((int) $row['id'], (string) $row['email']);
    }
}
