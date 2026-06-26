<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;

/**
 * In-test command handler that records whether it ran and which command instance
 * it received, so the test can prove the bus routed to exactly this handler.
 *
 * @implements CommandHandler<FakeCommand>
 */
final class FakeCommandHandler implements CommandHandler
{
    public bool $handled = false;

    public ?FakeCommand $received = null;

    #[\Override]
    public function __invoke(Command $command): void
    {
        $this->handled = true;
        $this->received = $command instanceof FakeCommand ? $command : null;
    }
}
