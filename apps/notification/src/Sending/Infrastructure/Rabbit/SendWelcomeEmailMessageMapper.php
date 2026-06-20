<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;

/**
 * Maps a `SendWelcomeEmail/v1` wire-format JSON body to a {@see WelcomeEmail}.
 *
 * Anti-corruption boundary: untrusted bytes where "malformed" is an expected
 * outcome the consumer routes to the DLQ. Unknown fields (e.g. `occurredAt`,
 * future additions) are tolerated — only the required fields are read.
 *
 * Wire-format mapping (arch §7 / contracts/send-welcome-email.v1.json):
 *
 * | WelcomeEmail property | wire JSON path  | required |
 * |-----------------------|-----------------|----------|
 * | sagaId                | sagaId          | yes      |
 * | subscriptionId        | subscriptionId  | yes      |
 * | recipientEmail        | email           | yes      |
 * | repository            | repository      | yes      |
 */
final readonly class SendWelcomeEmailMessageMapper
{
    private const EXPECTED_SCHEMA = 'SendWelcomeEmail/v1';

    public function fromJson(string $json): WelcomeEmail
    {
        $payload = $this->decode($json);

        $schema = $this->requireString($payload, 'schema');
        if ($schema !== self::EXPECTED_SCHEMA) {
            throw new MalformedWelcomeEmailMessageException(sprintf(
                'SendWelcomeEmail/v1 message has unknown schema "%s"; expected "%s".',
                $schema,
                self::EXPECTED_SCHEMA,
            ));
        }

        // Extract every primitive first (these throw and propagate), so the only
        // code inside the value-object try below is VO construction — a future bug
        // elsewhere can never be silently reclassified as a poison message.
        $sagaId = $this->requireString($payload, 'sagaId');
        $subscriptionId = $this->requireInt($payload, 'subscriptionId');
        $email = $this->requireString($payload, 'email');
        $repository = $this->requireString($payload, 'repository');

        // A self-validating VO rejecting a present-but-invalid value (bad email,
        // malformed repo) is just another flavour of poison message — translate
        // ONLY that to MalformedWelcomeEmailMessageException.
        try {
            $recipientEmail = new EmailAddress($email);
            $repositoryName = new RepositoryName($repository);
        } catch (\InvalidArgumentException $e) {
            throw new MalformedWelcomeEmailMessageException(
                'SendWelcomeEmail/v1 message has an invalid field: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new WelcomeEmail(
            sagaId: $sagaId,
            subscriptionId: $subscriptionId,
            recipientEmail: $recipientEmail,
            repository: $repositoryName,
        );
    }

    /** @return array<array-key, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedWelcomeEmailMessageException(
                'SendWelcomeEmail/v1 message body is not valid JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedWelcomeEmailMessageException(
                'SendWelcomeEmail/v1 message body must decode to a JSON object, got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new MalformedWelcomeEmailMessageException(
                "SendWelcomeEmail/v1 message is missing required integer field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value)) {
            throw new MalformedWelcomeEmailMessageException(
                "SendWelcomeEmail/v1 message is missing required string field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }
}
