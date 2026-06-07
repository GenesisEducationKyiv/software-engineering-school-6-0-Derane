<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Serialization;

use App\Notification\Publishing\Domain\SendReleaseEmail;

/**
 * Maps SendReleaseEmail to the wire-format JSON described by architecture §7
 * (schema "SendReleaseEmail/v1"). This is the wire-format mapper C5's
 * RabbitReleaseNotificationPublisher will use to produce the message body —
 * the contract pinned by SendReleaseEmailSerializerTest must stay byte-stable
 * (additive-only) across C1→C5→D3→D4.
 *
 * Datetimes are formatted as RFC3339 (\DateTimeInterface::RFC3339 — the same
 * format string as ::ATOM, no fractional seconds), matching §7's "RFC3339"
 * placeholder, e.g. "2026-06-07T12:00:00+00:00".
 *
 * @psalm-api
 */
final readonly class SendReleaseEmailSerializer
{
    /** @return array{schema: string, eventId: string, occurredAt: string, subscriptionId: int, email: string, repository: string, release: array{tagName: string, name: string, htmlUrl: string, publishedAt: string}} */
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
            ],
        ];
    }

    public function toJson(SendReleaseEmail $message): string
    {
        return json_encode($this->toArray($message), JSON_THROW_ON_ERROR);
    }
}
