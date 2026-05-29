<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Carries the matched route *pattern* from inside the routing middleware (the
 * writer, {@see \App\Middleware\RouteTagMiddleware}) out to the metrics
 * middleware (the reader, {@see \App\Middleware\RequestMetricsMiddleware}) that
 * runs outside routing, so the RED metric keeps a low-cardinality route label.
 *
 * HTTP-only concern, distinct from the correlation id; the same holder
 * implements both so a single {@see CorrelationContextInterface::reset()} clears
 * the whole request scope.
 */
interface RouteContextInterface
{
    public function setRoute(string $route): void;

    public function route(): ?string;
}
