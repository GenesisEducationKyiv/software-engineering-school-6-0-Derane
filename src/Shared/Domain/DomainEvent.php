<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Contract for an in-process domain event recorded by an aggregate and dispatched
 * synchronously on the PSR-14 plane (A3) inside the same request/process.
 *
 * This is deliberately NOT the wire-serialized integration message (e.g.
 * SendReleaseEmail, C1): those cross a process/queue boundary, carry a versioned
 * schema plus dedupe/idempotency metadata, and are a separate concern. A
 * DomainEvent never leaves the process, so it stays lean — a timestamp and a
 * stable routing name only.
 *
 * @psalm-api
 */
interface DomainEvent
{
    public function occurredOn(): \DateTimeImmutable;

    public function eventName(): string;
}
