<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Bus\Command\CommandNotRegistered;

/**
 * In-memory command bus backed by an immutable, constructor-injected map of
 * command class name -> its single handler.
 *
 * The map is supplied once at wire time (the DI container is the composition root)
 * and never mutated at runtime — there is no register()/bind() method — which
 * keeps the class `final readonly` (same rationale as A3's ListenerProvider). A
 * CommandHandler is an object with __invoke, so it is callable; invoking it
 * directly keeps the boundary free of `mixed`.
 *
 * @psalm-api
 */
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
