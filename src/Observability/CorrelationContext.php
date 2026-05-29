<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Per-process holder for the correlation id of the current unit of work
 * (one HTTP request, one gRPC call, one scan cycle).
 *
 * Mutable by design — the running process rebinds the id for each unit and
 * resets it afterwards. Read by {@see \App\Observability\Logging\ContextProcessor}
 * so every log line carries the same correlation id. Justified non-readonly
 * state, like {@see \App\Cache\SafeGitHubCacheDecorator}.
 */
final class CorrelationContext
{
    private ?string $id = null;

    public function start(string $id): void
    {
        $this->id = $id;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function reset(): void
    {
        $this->id = null;
    }
}
