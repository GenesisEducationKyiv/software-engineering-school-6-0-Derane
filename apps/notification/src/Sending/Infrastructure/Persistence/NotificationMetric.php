<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Persistence;

/**
 * The `notification_metrics.metric_name` identities. Naming each counter exactly
 * once removes the stringly-typed mismatch risk between the increment (write) and
 * count (read) sites.
 */
enum NotificationMetric: string
{
    case Consumed = 'consumed_total';
    case Failed = 'failed_total';
    case Contention = 'contention_total';
    case Dlq = 'dlq_total';
    case Delivered = 'delivered_total';
    case Deduped = 'deduped_total';
    case Superseded = 'superseded_total';
}
