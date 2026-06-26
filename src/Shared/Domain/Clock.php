<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Port for "what time is it now" — handlers and factories take this instead
 * of calling `new \DateTimeImmutable()` inline, making timestamps
 * deterministic in tests and pinning the timezone in one adapter.
 *
 * @psalm-api
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
