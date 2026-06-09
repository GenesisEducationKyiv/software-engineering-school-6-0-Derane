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
 *
 * Implements two narrow interfaces so each consumer depends only on the concern
 * it uses: the correlation id ({@see CorrelationContextInterface}) and the HTTP
 * route tag ({@see RouteContextInterface}).
 *
 * @psalm-api
 */
final class CorrelationContext implements CorrelationContextInterface, RouteContextInterface
{
    private ?string $id = null;
    private ?string $route = null;

    #[\Override]
    public function start(string $id): void
    {
        $this->id = $id;
    }

    #[\Override]
    public function id(): ?string
    {
        return $this->id;
    }

    /** HTTP-only: the matched Slim route pattern for the current request, set after routing. */
    #[\Override]
    public function setRoute(string $route): void
    {
        $this->route = $route;
    }

    #[\Override]
    public function route(): ?string
    {
        return $this->route;
    }

    #[\Override]
    public function reset(): void
    {
        $this->id = null;
        $this->route = null;
    }
}
