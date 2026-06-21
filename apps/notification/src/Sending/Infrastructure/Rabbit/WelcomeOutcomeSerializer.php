<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\WelcomeOutcome;

/**
 * Serializes the `WelcomeEmailOutcome/v1` reply to its wire JSON shape
 * (arch §7 / contracts/welcome-email-outcome.v1.json).
 *
 * Extracted from {@see RabbitWelcomeOutcomePublisher} so the body shape — the
 * cross-service contract — is unit-testable against the golden file WITHOUT a
 * live broker. `occurredAt` is injected (not read from a wall clock inside) so
 * the output is deterministic; the publisher passes `new \DateTimeImmutable()`.
 *
 * `error` is carried only on a `Failed` outcome; a `Sent` reply always nulls it,
 * regardless of any string passed in, so the two-state invariant lives in one
 * place. Datetimes are RFC3339 (= ::ATOM, no fractional seconds), matching the
 * monolith mapper's expectations.
 */
final readonly class WelcomeOutcomeSerializer
{
    /**
     * @return array{
     *     schema: string,
     *     sagaId: string,
     *     subscriptionId: int,
     *     outcome: string,
     *     error: string|null,
     *     occurredAt: string
     * }
     */
    public function toArray(
        string $sagaId,
        int $subscriptionId,
        WelcomeOutcome $outcome,
        ?string $error,
        \DateTimeImmutable $occurredAt,
    ): array {
        return [
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => $sagaId,
            'subscriptionId' => $subscriptionId,
            'outcome' => $outcome->value,
            'error' => $outcome === WelcomeOutcome::Failed ? $error : null,
            'occurredAt' => $occurredAt->format(\DateTimeInterface::RFC3339),
        ];
    }

    public function toJson(
        string $sagaId,
        int $subscriptionId,
        WelcomeOutcome $outcome,
        ?string $error,
        \DateTimeImmutable $occurredAt,
    ): string {
        return json_encode(
            $this->toArray($sagaId, $subscriptionId, $outcome, $error, $occurredAt),
            JSON_THROW_ON_ERROR,
        );
    }
}
