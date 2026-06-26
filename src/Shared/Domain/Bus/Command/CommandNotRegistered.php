<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/**
 * \LogicException because an unbound command is a wiring/programming error — the
 * handler map was assembled incompletely in the composition root — not a runtime
 * input error.
 *
 * @psalm-api
 */
final class CommandNotRegistered extends \LogicException
{
    public function __construct(Command $command)
    {
        parent::__construct(sprintf('No command handler registered for "%s"', $command::class));
    }
}
