<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\ReleaseTag;
use App\Sending\Domain\RepositoryName;

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

    private JsonMessageReader $reader;

    public function __construct()
    {
        $this->reader = new JsonMessageReader(
            self::EXPECTED_SCHEMA,
            static fn (string $message, ?\Throwable $previous): \RuntimeException
                => new MalformedReleaseEmailMessageException($message, previous: $previous),
        );
    }

    public function fromJson(string $json): ReleaseEmail
    {
        $payload = $this->reader->decode($json);

        $schema = $this->requireString($payload, 'schema');
        if ($schema !== self::EXPECTED_SCHEMA) {
            throw new MalformedReleaseEmailMessageException(sprintf(
                'SendReleaseEmail/v1 message has unknown schema "%s"; expected "%s".',
                $schema,
                self::EXPECTED_SCHEMA,
            ));
        }

        $release = $this->requireObject($payload, 'release');

        // Extract every primitive first (these throw MalformedReleaseEmailMessageException
        // and propagate), preserving field evaluation order, so the only code inside the
        // value-object try below is VO construction — a future bug elsewhere in this
        // method can never be silently reclassified as a poison message.
        $eventId = $this->requireString($payload, 'eventId');
        $subscriptionId = $this->reader->requireInt($payload, 'subscriptionId');
        $email = $this->requireString($payload, 'email');
        $repository = $this->requireString($payload, 'repository');
        $tagName = $this->requireString($release, 'release.tagName', 'tagName');
        $releaseName = $this->requireString($release, 'release.name', 'name');
        $releaseBody = $this->optionalString($release, 'body');
        $releaseUrl = $this->requireHttpUrl($release, 'release.htmlUrl', 'htmlUrl');
        $publishedAt = $this->requireString($release, 'release.publishedAt', 'publishedAt');

        // A self-validating VO rejecting a present-but-invalid value (bad email,
        // malformed repo, empty tag) is just another flavour of poison message —
        // translate ONLY that to MalformedReleaseEmailMessageException.
        try {
            $recipientEmail = new EmailAddress($email);
            $repositoryName = new RepositoryName($repository);
            $releaseTag = new ReleaseTag($tagName);
        } catch (\InvalidArgumentException $e) {
            throw new MalformedReleaseEmailMessageException(
                'SendReleaseEmail/v1 message has an invalid field: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new ReleaseEmail(
            eventId: $eventId,
            subscriptionId: $subscriptionId,
            recipientEmail: $recipientEmail,
            repository: $repositoryName,
            tagName: $releaseTag,
            releaseName: $releaseName,
            releaseBody: $releaseBody,
            releaseUrl: $releaseUrl,
            publishedAt: $publishedAt,
        );
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

    /**
     * Nested-key variant kept local to this mapper: when reading a field from a
     * nested object the wire path (`$field`, reported in errors) differs from the
     * lookup key (`$key`). The simple single-field case delegates to the shared
     * reader so the type-guard text stays byte-identical.
     *
     * @param array<array-key, mixed> $payload
     */
    private function requireString(array $payload, string $field, ?string $key = null): string
    {
        if ($key === null) {
            return $this->reader->requireString($payload, $field);
        }

        $value = $payload[$key] ?? null;

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
