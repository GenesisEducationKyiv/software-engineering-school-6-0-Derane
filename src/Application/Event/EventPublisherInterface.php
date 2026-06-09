<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * App-owned boundary for emitting application/telemetry events, with an explicit
 * fire-and-forget, no-throw contract: business code publishes a fact through this
 * interface and MUST be able to assume that publishing never throws and never
 * changes the business outcome, however many observability sinks run behind it.
 *
 * This is deliberately narrower than PSR-14's `EventDispatcherInterface`, whose
 * contract lets a listener exception bubble up. The fail-open guarantee lives here,
 * in the type services depend on — not in whichever concrete dispatcher is wired.
 *
 * @psalm-api
 */
interface EventPublisherInterface
{
    public function publish(object $event): void;
}
