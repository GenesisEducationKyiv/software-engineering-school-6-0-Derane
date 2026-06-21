<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\SagaId;

/**
 * Maps a `WelcomeEmailOutcome/v1` reply body to a HandleWelcomeEmailOutcomeCommand.
 *
 * Anti-corruption boundary: untrusted bytes where "malformed" is an expected
 * outcome the consumer logs + acks-and-drops (the reply queue has no DLX, arch §7).
 * Unknown fields (e.g. occurredAt, error on a sent reply) are tolerated — only the
 * required fields are read.
 *
 * Wire-format mapping (arch §7 / contracts/welcome-email-outcome.v1.json):
 *
 * | Command property | wire JSON path | required |
 * |------------------|----------------|----------|
 * | sagaId           | sagaId         | yes (UUID v4) |
 * | subscriptionId   | subscriptionId | yes      |
 * | outcome          | outcome        | yes ("sent"|"failed") |
 *
 * @psalm-api
 */
final readonly class WelcomeEmailOutcomeMessageMapper
{
    public const string EXPECTED_SCHEMA = 'WelcomeEmailOutcome/v1';

    public function fromJson(string $json): HandleWelcomeEmailOutcomeCommand
    {
        $payload = $this->decode($json);

        $schema = $this->requireString($payload, 'schema');
        if ($schema !== self::EXPECTED_SCHEMA) {
            throw new MalformedWelcomeEmailOutcomeException(sprintf(
                'WelcomeEmailOutcome message has unknown schema "%s"; expected "%s".',
                $schema,
                self::EXPECTED_SCHEMA,
            ));
        }

        $sagaId = $this->requireString($payload, 'sagaId');
        $subscriptionId = $this->requireInt($payload, 'subscriptionId');
        $outcome = $this->requireString($payload, 'outcome');

        // A present-but-invalid sagaId (not a UUID v4) or an unknown outcome value
        // is just another flavour of poison reply — translate ONLY that to the
        // malformed exception, so a genuine bug elsewhere is never swallowed.
        try {
            // Validate the UUID shape eagerly; the command carries the primitive.
            SagaId::fromString($sagaId);
        } catch (InvalidArgumentException $e) {
            throw new MalformedWelcomeEmailOutcomeException(
                'WelcomeEmailOutcome message has an invalid sagaId: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $welcomeOutcome = WelcomeOutcome::tryFrom($outcome);
        if ($welcomeOutcome === null) {
            throw new MalformedWelcomeEmailOutcomeException(sprintf(
                'WelcomeEmailOutcome message has an unknown outcome "%s"; expected "sent" or "failed".',
                $outcome,
            ));
        }

        return new HandleWelcomeEmailOutcomeCommand($sagaId, $subscriptionId, $welcomeOutcome);
    }

    /** @return array<array-key, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedWelcomeEmailOutcomeException(
                'WelcomeEmailOutcome message body is not valid JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedWelcomeEmailOutcomeException(
                'WelcomeEmailOutcome message body must decode to a JSON object, got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new MalformedWelcomeEmailOutcomeException(
                "WelcomeEmailOutcome message is missing required integer field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value)) {
            throw new MalformedWelcomeEmailOutcomeException(
                "WelcomeEmailOutcome message is missing required string field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }
}
