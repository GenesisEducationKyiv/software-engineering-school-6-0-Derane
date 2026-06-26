<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Bus\Command\CommandNotRegistered;

/** @psalm-api */
final readonly class InMemoryCommandBus implements CommandBus
{
    /**
     * @param array<class-string<Command>, CommandHandler> $handlers
     */
    public function __construct(private array $handlers)
    {
    }

    #[\Override]
    public function dispatch(Command $command): void
    {
        $handler = $this->handlers[$command::class] ?? throw new CommandNotRegistered($command);

        $handler($command);
    }
}
