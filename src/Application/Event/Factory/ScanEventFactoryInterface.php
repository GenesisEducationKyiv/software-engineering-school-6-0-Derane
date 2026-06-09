<?php

declare(strict_types=1);

namespace App\Application\Event\Factory;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ReleaseMarkerWithheld;
use App\Application\Event\RepositoryScanFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\ScanCycleFailed;
use App\Application\Event\ScanCycleStarted;
use App\Application\Event\ScanInterruptedByRateLimit;

/**
 * Builds the application events the scanner orchestration emits. Injected into
 * {@see \App\Service\ScannerService} so the orchestrator states facts through an
 * abstraction instead of constructing event DTOs with `new`.
 */
interface ScanEventFactoryInterface
{
    public function cycleStarted(int $repositoryCount): ScanCycleStarted;

    public function cycleCompleted(int $repositoryCount, float $durationSeconds): ScanCycleCompleted;

    public function cycleFailed(\Throwable $error): ScanCycleFailed;

    public function repositoryFailed(string $repository, \Throwable $error): RepositoryScanFailed;

    public function interruptedByRateLimit(string $repository, string $retryAfter): ScanInterruptedByRateLimit;

    public function notificationBatchCompleted(
        string $repository,
        ?string $tag,
        bool $allDelivered
    ): NotificationBatchCompleted;

    public function releaseMarkerWithheld(string $repository, ?string $tag): ReleaseMarkerWithheld;
}
