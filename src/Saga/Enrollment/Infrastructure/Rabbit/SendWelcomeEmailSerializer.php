<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Domain\SendWelcomeEmail;

/**
 * Serializes the SendWelcomeEmail integration message to its wire JSON
 * (schema = SendWelcomeEmail/v1, arch §7 / contracts/send-welcome-email.v1.json).
 *
 * Datetimes are formatted as RFC3339 (= ::ATOM, no fractional seconds), mirroring
 * SendReleaseEmailSerializer — the consumer's schema contract depends on this.
 * The AMQP envelope fields (content_type, delivery_mode, correlation_id) are set
 * by the relay, not here; this only shapes the body.
 *
 * @psalm-api
 */
final readonly class SendWelcomeEmailSerializer
{
    /**
     * @return array{
     *     schema: string,
     *     sagaId: string,
     *     subscriptionId: int,
     *     email: string,
     *     repository: string,
     *     occurredAt: string
     * }
     */
    public function toArray(SendWelcomeEmail $message): array
    {
        return [
            'schema' => $message->schema,
            'sagaId' => $message->sagaId,
            'subscriptionId' => $message->subscriptionId,
            'email' => $message->email->value(),
            'repository' => $message->repository->value(),
            'occurredAt' => $message->occurredAt->format(\DateTimeInterface::RFC3339),
        ];
    }

    public function toJson(SendWelcomeEmail $message): string
    {
        return json_encode($this->toArray($message), JSON_THROW_ON_ERROR);
    }
}
