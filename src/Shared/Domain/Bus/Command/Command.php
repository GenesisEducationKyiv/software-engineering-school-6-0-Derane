<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Command;

/**
 * Marker interface for a CQRS command — a write-side use-case request routed to
 * exactly one handler by the command bus.
 *
 * Deliberately empty: a command is a plain, immutable intent DTO. The bus keys on
 * the concrete command class, so this marker only carries type identity.
 *
 * @psalm-api
 */
interface Command
{
}
