<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Serialization;

use App\Notification\Publishing\Domain\SendReleaseEmail;

/**
 * Datetimes are formatted as RFC3339 (= ::ATOM, no fractional seconds),
 * e.g. "2026-06-07T12:00:00+00:00". Changing this would break the
 * consumer's schema contract.
 *
 * @psalm-api
 */
final readonly class SendReleaseEmailSerializer
{
    /** @return array{schema: string, eventId: string, occurredAt: string, subscriptionId: int, email: string, repository: string, release: array{tagName: string, name: string, htmlUrl: string, publishedAt: string, body: string}} */
    public function toArray(SendReleaseEmail $message): array
    {
        return [
            'schema' => $message->schema,
            'eventId' => $message->eventId,
            'occurredAt' => $message->occurredAt->format(\DateTimeInterface::RFC3339),
            'subscriptionId' => $message->subscriptionId,
            'email' => $message->email->value(),
            'repository' => $message->repository->value(),
            'release' => [
                'tagName' => $message->release->tagName->value(),
                'name' => $message->release->name,
                'htmlUrl' => $message->release->htmlUrl,
                'publishedAt' => $message->release->publishedAt,
                'body' => $message->release->body,
            ],
        ];
    }

    public function toJson(SendReleaseEmail $message): string
    {
        return json_encode($this->toArray($message), JSON_THROW_ON_ERROR);
    }
}
