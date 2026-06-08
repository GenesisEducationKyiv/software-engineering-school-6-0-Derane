<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * In-process only — never crosses a process or queue boundary. Keep distinct
 * from cross-service integration messages (e.g. SendReleaseEmail) which carry
 * a versioned schema and idempotency metadata.
 *
 * @psalm-api
 */
interface DomainEvent
{
    public function occurredOn(): \DateTimeImmutable;

    public function eventName(): string;
}
