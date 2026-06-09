<?php

declare(strict_types=1);

namespace App\Application\Event\Factory;

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

/**
 * Single place the anemic application-event DTOs are constructed. Implements the
 * narrow per-consumer factory interfaces so each service depends only on the
 * events it emits, while sharing one stateless instance (see config/container.php).
 *
 * It is also the sanitization boundary: a thrown {@see \Throwable} is reduced to
 * scalar `errorClass`/`errorMessage` here so events stay serialization-safe value
 * objects and no live exception (with stack/previous) reaches a log sink.
 *
 * @psalm-api
 */
final readonly class ApplicationEventFactory implements
    ScanEventFactoryInterface,
    ReleaseEventFactoryInterface,
    NotificationEventFactoryInterface,
    SubscriptionEventFactoryInterface
{
    #[\Override]
    public function cycleStarted(int $repositoryCount): ScanCycleStarted
    {
        return new ScanCycleStarted($repositoryCount);
    }

    #[\Override]
    public function cycleCompleted(int $repositoryCount, float $durationSeconds): ScanCycleCompleted
    {
        return new ScanCycleCompleted($repositoryCount, $durationSeconds);
    }

    #[\Override]
    public function cycleFailed(\Throwable $error): ScanCycleFailed
    {
        return new ScanCycleFailed($error::class, $error->getMessage());
    }

    #[\Override]
    public function repositoryFailed(string $repository, \Throwable $error): RepositoryScanFailed
    {
        return new RepositoryScanFailed($repository, $error::class, $error->getMessage());
    }

    #[\Override]
    public function interruptedByRateLimit(string $repository, string $retryAfter): ScanInterruptedByRateLimit
    {
        return new ScanInterruptedByRateLimit($repository, $retryAfter);
    }

    #[\Override]
    public function notificationBatchCompleted(
        string $repository,
        ?string $tag,
        bool $allDelivered
    ): NotificationBatchCompleted {
        return new NotificationBatchCompleted($repository, $tag, $allDelivered);
    }

    #[\Override]
    public function releaseMarkerWithheld(string $repository, ?string $tag): ReleaseMarkerWithheld
    {
        return new ReleaseMarkerWithheld($repository, $tag);
    }

    #[\Override]
    public function releaseDetected(string $repository, string $tag, ?string $previousTag): ReleaseDetected
    {
        return new ReleaseDetected($repository, $tag, $previousTag);
    }

    #[\Override]
    public function notificationSent(string $email, string $repository, ?string $tag): ReleaseNotificationSent
    {
        return new ReleaseNotificationSent($email, $repository, $tag);
    }

    #[\Override]
    public function notificationFailed(string $email, string $repository, \Throwable $error): ReleaseNotificationFailed
    {
        return new ReleaseNotificationFailed($email, $repository, $error::class, $error->getMessage());
    }

    #[\Override]
    public function subscriptionCreated(string $email, string $repository): SubscriptionCreated
    {
        return new SubscriptionCreated($email, $repository);
    }

    #[\Override]
    public function subscriptionDeleted(int $id): SubscriptionDeleted
    {
        return new SubscriptionDeleted($id);
    }
}
