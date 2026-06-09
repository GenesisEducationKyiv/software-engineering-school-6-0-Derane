<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Correlation id of the current unit of work (one HTTP request, one gRPC call,
 * one scan cycle). The lifecycle owner — the outermost middleware, the gRPC
 * invoker, or the scanner loop — calls {@see start()} and {@see reset()};
 * {@see \App\Observability\Logging\ContextProcessor} reads {@see id()} to stamp
 * every log line.
 */
interface CorrelationContextInterface
{
    public function start(string $id): void;

    public function id(): ?string;

    /** Clears all request-scoped state for the current unit of work. */
    public function reset(): void;
}
