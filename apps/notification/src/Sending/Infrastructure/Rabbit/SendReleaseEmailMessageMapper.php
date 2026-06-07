<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\ReleaseEmail;

/**
 * Maps a `SendReleaseEmail/v1` wire-format JSON message body to a `ReleaseEmail`.
 *
 * ## Why this is a plain mapper, not a `*FactoryInterface` (Technical Decisions §2)
 *
 * The project convention is `*FactoryInterface` for anemic DTOs constructed
 * from external payloads (`SendReleaseEmailFactoryInterface`/`ReleaseFactoryInterface`).
 * Both of those, though, take **already-validated, already-typed inputs** —
 * `SendReleaseEmailFactory::fromRecipient()` assembles from Domain VOs
 * (`EmailAddress`, `RepositoryName`, …) plus side-effecting concerns (UUID,
 * timestamp); `ReleaseFactory` parses a GitHub API response in a context where
 * a malformed field is a genuine, loud integration error.
 *
 * What this class does is structurally different: it parses **untrusted bytes
 * off the wire**, where "malformed" is an *expected, routine, must-not-crash*
 * outcome the caller (the consumer) needs to detect and route to the DLQ —
 * not a Domain-construction concern with optional/default-able fields. Forcing
 * this into a `from*(...)`/`fromArray(array $payload): ReleaseEmail` factory
 * shape would either push the JSON-decode + validation into the consumer
 * (defeating the point of a separate collaborator) or produce a factory that
 * throws generic `\TypeError`/`\InvalidArgumentException` the consumer has no
 * narrow way to distinguish from "this factory has a bug".
 *
 * This is **not** a `*FactoryInterface`: it has no interface (nothing else
 * will ever implement "parse `SendReleaseEmail/v1` JSON" — it is the
 * anti-corruption layer's private concern, used by exactly one caller,
 * `SendReleaseEmailConsumer`), and its job — validate untrusted input, signal
 * poison vs. success via a single dedicated, narrowly-typed exception
 * ({@see MalformedReleaseEmailMessageException}) — is qualitatively different
 * from "construct a VO from already-valid Domain inputs". Do not "fix" this
 * into a `*FactoryInterface` out of convention-matching reflex.
 *
 * ## Wire-format mapping (Technical Decisions §6 — re-derived from
 * `SendReleaseEmailSerializer::toArray()`, byte-for-byte)
 *
 * | `ReleaseEmail` property | wire JSON path      | required type |
 * |-------------------------|---------------------|---------------|
 * | `subscriptionId`        | `subscriptionId`    | `int`         |
 * | `recipientEmail`        | `email`             | `string`      |
 * | `repository`            | `repository`        | `string`      |
 * | `tagName`               | `release.tagName`   | `string`      |
 * | `releaseName`           | `release.name`      | `string`      |
 * | `releaseUrl`            | `release.htmlUrl`   | `string`      |
 * | `publishedAt`           | `release.publishedAt` | `string`    |
 *
 * `schema`/`eventId`/`occurredAt` are envelope/correlation fields — D3
 * deliberately excluded them from `ReleaseEmail`; this mapper does not read
 * them into the VO (a future logging/correlation hook MAY read `eventId`
 * directly off the decoded payload without involving `ReleaseEmail`).
 *
 * Every one of the seven paths above is checked for BOTH presence AND type.
 * `json_decode($json, true)` produces untyped `mixed` values — a
 * `subscriptionId` that decodes as the JSON string `"42"`, a `null`
 * `release.tagName`, or a missing `release` object entirely are all malformed
 * and throw {@see MalformedReleaseEmailMessageException}. We never
 * coerce/cast-and-hope: a partially-bad message must never silently produce a
 * `ReleaseEmail` with wrong/empty data that gets emailed to a real subscriber.
 */
final readonly class SendReleaseEmailMessageMapper
{
    /**
     * @throws MalformedReleaseEmailMessageException when `$json` is not valid
     *         JSON, is not a JSON object, or is missing/wrong-types any of the
     *         seven required fields.
     */
    public function fromJson(string $json): ReleaseEmail
    {
        $payload = $this->decode($json);

        $release = $this->requireObject($payload, 'release');

        return new ReleaseEmail(
            subscriptionId: $this->requireInt($payload, 'subscriptionId'),
            recipientEmail: $this->requireString($payload, 'email'),
            repository: $this->requireString($payload, 'repository'),
            tagName: $this->requireString($release, 'release.tagName', 'tagName'),
            releaseName: $this->requireString($release, 'release.name', 'name'),
            releaseUrl: $this->requireString($release, 'release.htmlUrl', 'htmlUrl'),
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
}
