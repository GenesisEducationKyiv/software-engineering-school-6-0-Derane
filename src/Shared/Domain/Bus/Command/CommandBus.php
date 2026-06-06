<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/**
 * In-house CQRS command bus — NOT Symfony Messenger (architecture §1/§5).
 *
 * A thin driver (controller, gRPC handler, CLI scanner loop) builds a Command and
 * hands it here; the bus routes it to its single registered handler. Write-side:
 * no return value.
 *
 * @psalm-api
 */
interface CommandBus
{
    public function dispatch(Command $command): void;
}
