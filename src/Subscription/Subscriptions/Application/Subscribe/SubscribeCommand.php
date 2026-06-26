<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class SubscribeCommand implements Command
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}
