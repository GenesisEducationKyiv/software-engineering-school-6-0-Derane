<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Unsubscribe;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class UnsubscribeCommand implements Command
{
    public function __construct(public int $id)
    {
    }
}
