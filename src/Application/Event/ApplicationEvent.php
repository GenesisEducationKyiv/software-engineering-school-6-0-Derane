<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Marker for the anemic, readonly application/telemetry events services emit to
 * state a fact ("a release was detected", "a scan cycle completed"). These are
 * orchestration facts, not domain-model events; observability listeners decide
 * how each fact is represented (metrics, structured logs).
 *
 * @psalm-api
 */
interface ApplicationEvent
{
}
