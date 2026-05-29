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
    private ?string $route = null;

    public function start(string $id): void
    {
        $this->id = $id;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** HTTP-only: the matched Slim route pattern for the current request, set after routing. */
    public function setRoute(string $route): void
    {
        $this->route = $route;
    }

    public function route(): ?string
    {
        return $this->route;
    }

    public function reset(): void
    {
        $this->id = null;
        $this->route = null;
    }
}
