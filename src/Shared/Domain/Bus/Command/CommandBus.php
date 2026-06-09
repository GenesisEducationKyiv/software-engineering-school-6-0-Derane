<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/** In-house CQRS command bus — NOT Symfony Messenger. @psalm-api */
interface CommandBus
{
    public function dispatch(Command $command): void;
}
