<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Command\Command;

/**
 * In-test command with no handler in the map, used to prove the bus throws
 * CommandNotRegistered naming this class.
 */
final class UnboundCommand implements Command
{
}
