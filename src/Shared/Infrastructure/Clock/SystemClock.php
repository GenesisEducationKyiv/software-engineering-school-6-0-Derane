<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Clock;

use App\Shared\Domain\Clock;

/**
 * Pins UTC regardless of the PHP default timezone, so every timestamp the
 * application stamps (event occurredOn, message occurredAt, createdAt) is
 * offset-stable across deployment environments.
 *
 * @psalm-api
 */
final readonly class SystemClock implements Clock
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
