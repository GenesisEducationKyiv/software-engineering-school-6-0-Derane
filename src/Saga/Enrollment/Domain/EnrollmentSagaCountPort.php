<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * State-shaped saga counters, read by MetricsService exactly like
 * SubscriptionCountPort / RepositoryCountPort (no cross-context table reach-in):
 *
 *  - confirmedTotal()  = COUNT(*) WHERE state = 'completed'
 *  - cancelledTotal()  = COUNT(*) WHERE state = 'compensated'
 *
 * These are derivable from the saga table itself, so they are COUNTs (gauges), not
 * persisted event counters (FR12/AC7, arch §10).
 *
 * @psalm-api
 */
interface EnrollmentSagaCountPort
{
    public function confirmedTotal(): int;

    public function cancelledTotal(): int;
}
