<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\ReleaseEmail;

/**
 * Maps a `SendReleaseEmail/v1` wire-format JSON message body to a `ReleaseEmail`.
 *
 * Plain mapper rather than a `*FactoryInterface`: it parses untrusted bytes
 * where "malformed" is an expected outcome the caller must detect and route to
 * the DLQ — not a Domain-construction concern. The dedicated
 * {@see MalformedReleaseEmailMessageException} lets the consumer distinguish a
 * poison message from an environmental failure without catching \JsonException
 * or \TypeError, which could also leak from a mapper bug.
 *
 * Wire-format mapping (mirrors SendReleaseEmailSerializer::toArray()):
 *
 * | ReleaseEmail property | wire JSON path        | required |
 * |-----------------------|-----------------------|----------|
 * | eventId               | eventId               | yes      |
 * | subscriptionId        | subscriptionId        | yes      |
 * | recipientEmail        | email                 | yes      |
 * | repository            | repository            | yes      |
 * | tagName               | release.tagName       | yes      |
 * | releaseName           | release.name          | yes      |
 * | releaseBody           | release.body          | no (defaults to '') |
 * | releaseUrl            | release.htmlUrl       | yes      |
 * | publishedAt           | release.publishedAt   | yes      |
 */
final readonly class SendReleaseEmailMessageMapper
{
    private const EXPECTED_SCHEMA = 'SendReleaseEmail/v1';

    public function fromJson(string $json): ReleaseEmail
    {
        $payload = $this->decode($json);

        $schema = $this->requireString($payload, 'schema');
        if ($schema !== self::EXPECTED_SCHEMA) {
            throw new MalformedReleaseEmailMessageException(sprintf(
                'SendReleaseEmail/v1 message has unknown schema "%s"; expected "%s".',
                $schema,
                self::EXPECTED_SCHEMA,
            ));
        }

        $release = $this->requireObject($payload, 'release');

        return new ReleaseEmail(
            eventId: $this->requireString($payload, 'eventId'),
            subscriptionId: $this->requireInt($payload, 'subscriptionId'),
            recipientEmail: $this->requireString($payload, 'email'),
            repository: $this->requireString($payload, 'repository'),
            tagName: $this->requireString($release, 'release.tagName', 'tagName'),
            releaseName: $this->requireString($release, 'release.name', 'name'),
            releaseBody: $this->optionalString($release, 'body'),
            releaseUrl: $this->requireHttpUrl($release, 'release.htmlUrl', 'htmlUrl'),
            publishedAt: $this->requireString($release, 'release.publishedAt', 'publishedAt'),
        );
    }

    /** @return array<array-key, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedReleaseEmailMessageException(
                'SendReleaseEmail/v1 message body is not valid JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedReleaseEmailMessageException(
                'SendReleaseEmail/v1 message body must decode to a JSON object, got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function requireObject(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            throw new MalformedReleaseEmailMessageException(
                "SendReleaseEmail/v1 message is missing required object field \"{$field}\" or it is not an object."
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new MalformedReleaseEmailMessageException(
                "SendReleaseEmail/v1 message is missing required integer field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireString(array $payload, string $field, ?string $key = null): string
    {
        $value = $payload[$key ?? $field] ?? null;

        if (!is_string($value)) {
            throw new MalformedReleaseEmailMessageException(
                "SendReleaseEmail/v1 message is missing required string field \"{$field}\" or it has the wrong type."
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requireHttpUrl(array $payload, string $field, ?string $key = null): string
    {
        $value = $this->requireString($payload, $field, $key);

        if (!preg_match('/^https?:\/\//i', $value)) {
            throw new MalformedReleaseEmailMessageException(
                "SendReleaseEmail/v1 field \"{$field}\" must be an http/https URL, got: {$value}"
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function optionalString(array $payload, string $key, string $default = ''): string
    {
        return isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : $default;
    }
}
