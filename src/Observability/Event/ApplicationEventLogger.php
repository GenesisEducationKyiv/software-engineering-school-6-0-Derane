<?php

declare(strict_types=1);

namespace App\Observability\Event;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ReleaseDetected;
use App\Application\Event\ReleaseMarkerWithheld;
use App\Application\Event\ReleaseNotificationFailed;
use App\Application\Event\ReleaseNotificationSent;
use App\Application\Event\RepositoryScanFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\ScanCycleFailed;
use App\Application\Event\ScanCycleStarted;
use App\Application\Event\ScanInterruptedByRateLimit;
use App\Application\Event\SubscriptionCreated;
use App\Application\Event\SubscriptionDeleted;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The single place application events become structured logs. Every record carries
 * a stable `event` field (e.g. `release.detected`) plus typed context, so Kibana
 * can search and aggregate on it instead of on ad-hoc message text. PII redaction
 * (email masking) is centralized in
 * {@see \App\Observability\Logging\EmailRedactingProcessor} on the Monolog logger,
 * so this sink emits the raw fields. Component, env and correlation_id are added
 * downstream by {@see \App\Observability\Logging\ContextProcessor}.
 *
 * @psalm-api
 */
final readonly class ApplicationEventLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(object $event): void
    {
        $described = $this->describe($event);
        if ($described === null) {
            return;
        }

        [$level, $name, $context] = $described;
        $this->logger->log($level, $name, ['event' => $name] + $context);
    }

    /**
     * @return array{0: LogLevel::*, 1: non-empty-string, 2: array<string, scalar|null>}|null
     */
    private function describe(object $event): ?array
    {
        return match (true) {
            $event instanceof ScanCycleStarted => [LogLevel::INFO, 'scan.cycle_started', [
                'repository_count' => $event->repositoryCount,
            ]],
            $event instanceof ScanCycleCompleted => [LogLevel::INFO, 'scan.cycle_completed', [
                'repository_count' => $event->repositoryCount,
                'duration_ms' => round($event->durationSeconds * 1000.0, 2),
            ]],
            $event instanceof RepositoryScanFailed => [LogLevel::ERROR, 'scan.repository_failed', [
                'repository' => $event->repository,
                'error_class' => $event->errorClass,
                'error' => $event->errorMessage,
            ]],
            $event instanceof ScanInterruptedByRateLimit => [LogLevel::WARNING, 'scan.rate_limited', [
                'repository' => $event->repository,
                'retry_after' => $event->retryAfter,
            ]],
            $event instanceof ScanCycleFailed => [LogLevel::ERROR, 'scan.cycle_failed', [
                'error_class' => $event->errorClass,
                'error' => $event->errorMessage,
            ]],
            $event instanceof ReleaseDetected => [LogLevel::INFO, 'release.detected', [
                'repository' => $event->repository,
                'tag' => $event->tag,
                'previous_tag' => $event->previousTag,
            ]],
            $event instanceof NotificationBatchCompleted => [LogLevel::INFO, 'notification.batch_completed', [
                'repository' => $event->repository,
                'tag' => $event->tag,
                'result' => $event->allDelivered ? 'sent' : 'failed',
            ]],
            $event instanceof ReleaseMarkerWithheld => [LogLevel::WARNING, 'notification.marker_withheld', [
                'repository' => $event->repository,
                'tag' => $event->tag,
            ]],
            $event instanceof ReleaseNotificationSent => [LogLevel::INFO, 'notification.sent', [
                'email' => $event->email,
                'repository' => $event->repository,
                'tag' => $event->tag,
            ]],
            $event instanceof ReleaseNotificationFailed => [LogLevel::ERROR, 'notification.failed', [
                'email' => $event->email,
                'repository' => $event->repository,
                'error_class' => $event->errorClass,
                'error' => $event->errorMessage,
            ]],
            $event instanceof SubscriptionCreated => [LogLevel::INFO, 'subscription.created', [
                'email' => $event->email,
                'repository' => $event->repository,
            ]],
            $event instanceof SubscriptionDeleted => [LogLevel::INFO, 'subscription.deleted', [
                'id' => $event->id,
            ]],
            default => null,
        };
    }
}
