<?php

declare(strict_types=1);

namespace App\Application\Event\Factory;

use App\Application\Event\ReleaseNotificationFailed;
use App\Application\Event\ReleaseNotificationSent;

/**
 * Builds per-recipient notification events. Injected into
 * {@see \App\Service\NotifierService}.
 */
interface NotificationEventFactoryInterface
{
    public function notificationSent(string $email, string $repository, ?string $tag): ReleaseNotificationSent;

    public function notificationFailed(string $email, string $repository, \Throwable $error): ReleaseNotificationFailed;
}
