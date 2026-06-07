<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

/**
 * Port for publishing a SendReleaseEmail integration message to the cross-service
 * messaging plane (RabbitMQ — wired by C5's RabbitReleaseNotificationPublisher).
 *
 * Pure Domain interface: depends only on SendReleaseEmail (same package), zero
 * framework/IO/Infrastructure imports. Intentionally has no bound implementation
 * yet — C2 wires the listener that calls publish(), C5 introduces the RabbitMQ
 * adapter. An unbound port is correct for this seam story (see story C1, Decision 6).
 *
 * @psalm-api
 */
interface ReleaseNotificationPublisher
{
    public function publish(SendReleaseEmail $message): void;
}
