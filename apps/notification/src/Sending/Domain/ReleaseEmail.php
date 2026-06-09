<?php

declare(strict_types=1);

namespace App\Sending\Domain;

final readonly class ReleaseEmail
{
    public function __construct(
        public string $eventId,
        public int $subscriptionId,
        public string $recipientEmail,
        public string $repository,
        public string $tagName,
        public string $releaseName,
        public string $releaseBody,
        public string $releaseUrl,
        public string $publishedAt,
    ) {
    }

    public function key(): NotificationKey
    {
        return new NotificationKey($this->subscriptionId, $this->tagName, $this->repository);
    }
}
