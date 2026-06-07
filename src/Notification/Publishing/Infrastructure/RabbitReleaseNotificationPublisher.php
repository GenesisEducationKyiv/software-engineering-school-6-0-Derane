<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;

/** @psalm-api */
final readonly class RabbitReleaseNotificationPublisher implements ReleaseNotificationPublisher
{
    public function __construct(
        private RabbitPublisher $publisher,
        private SendReleaseEmailSerializer $serializer,
    ) {
    }

    #[\Override]
    public function publish(SendReleaseEmail $message): void
    {
        $this->publisher->publish(
            'notifications',
            'release.email',
            $this->serializer->toJson($message),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );
    }
}
