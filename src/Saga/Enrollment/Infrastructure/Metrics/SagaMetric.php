<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Metrics;

/**
 * The event-shaped monolith saga counters persisted in the 006 saga_metrics table
 * (FR12/AC7). The case values ARE the metric_name PKs and the public-funnel
 * spellings. State-shaped totals (confirmed/cancelled) are NOT here — they are
 * COUNTs over the saga table (EnrollmentSagaCountPort).
 *
 * @psalm-api
 */
enum SagaMetric: string
{
    case WelcomeCommandPublished = 'welcome_command_published_total';
    case WelcomeReplyConsumed = 'welcome_reply_consumed_total';
    case WelcomeReplyNoop = 'welcome_reply_noop_total';
    case TimeoutSwept = 'timeout_swept_total';
}
