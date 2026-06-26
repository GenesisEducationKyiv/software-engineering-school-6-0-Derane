<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Command\Command;

/**
 * In-test command that the bus is expected to route to FakeCommandHandler.
 */
final class FakeCommand implements Command
{
}
