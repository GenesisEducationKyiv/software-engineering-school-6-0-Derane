<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\SubscriberCollection;

/** @psalm-api */
interface SubscriberFinderInterface
{
    public function findSubscribersByRepository(string $repository): SubscriberCollection;
}
