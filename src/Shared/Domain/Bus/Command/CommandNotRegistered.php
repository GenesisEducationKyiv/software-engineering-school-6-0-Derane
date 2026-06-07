<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/**
 * Thrown by the command bus when a dispatched command has no registered handler.
 *
 * \LogicException because an unbound command is a wiring/programming error — the
 * handler map was assembled incompletely in the composition root — not a runtime
 * input error. Shared-owned with zero coupling to Shared\Infrastructure\Error\*
 * (the transport/HTTP-mapping namespace), keeping the Bus Domain free of
 * outer-layer dependencies. The message names the offending command class to make
 * the failure clear.
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
