<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

/** @psalm-api */
interface ReleaseNotificationPublisher
{
    public function publish(SendReleaseEmail $message): void;
}
