<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/**
 * Contract for the single handler that executes a given command.
 *
 * The runtime parameter type is the base Command so PHP's signature-compatibility
 * check passes; the @template lets a concrete handler narrow to its specific
 * command for Psalm (e.g. @implements CommandHandler<SubscribeCommand>) WITHOUT
 * changing the PHP signature — narrowing a parameter type in an implementing
 * method is a PHP fatal, so the narrowing is Psalm-only. These generics are
 * required for Psalm 100% / "no mixed leak across the bus boundary", not
 * decoration.
 *
 * @template T of Command
 *
 * @psalm-api
 */
interface CommandHandler
{
    /** @param T $command */
    public function __invoke(Command $command): void;
}
