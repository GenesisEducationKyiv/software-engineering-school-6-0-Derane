<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * Shared JSON poison-message reader for the `Send*Email/v1` wire mappers.
 *
 * Both {@see SendWelcomeEmailMessageMapper} and {@see SendReleaseEmailMessageMapper}
 * parse untrusted RabbitMQ bytes where "malformed" is an expected outcome routed
 * to the DLQ. The decode + simple-field guards were verbatim-identical between
 * them; this reader holds that single copy.
 *
 * It carries no schema knowledge of its own: the owning mapper injects its schema
 * label (e.g. `SendWelcomeEmail/v1`) and a factory that builds its own
 * Malformed*EmailMessageException, so the produced exception type and message text
 * stay byte-identical to the inlined versions.
 */
final class JsonMessageReader
{
    /** @var callable(string $message, ?\Throwable $previous): \RuntimeException */
    private $exceptionFactory;

    /**
     * @param callable(string $message, ?\Throwable $previous): \RuntimeException $exceptionFactory
     */
    public function __construct(
        private readonly string $schemaLabel,
        callable $exceptionFactory,
    ) {
        $this->exceptionFactory = $exceptionFactory;
    }

    /** @return array<array-key, mixed> */
    public function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ($this->exceptionFactory)(
                $this->schemaLabel . ' message body is not valid JSON: ' . $e->getMessage(),
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw ($this->exceptionFactory)(
                $this->schemaLabel . ' message body must decode to a JSON object, got '
                    . get_debug_type($decoded) . '.',
                null,
            );
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $payload */
    public function requireInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw ($this->exceptionFactory)(
                "{$this->schemaLabel} message is missing required integer field \"{$field}\" or it has the wrong type.",
                null,
            );
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    public function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value)) {
            throw ($this->exceptionFactory)(
                "{$this->schemaLabel} message is missing required string field \"{$field}\" or it has the wrong type.",
                null,
            );
        }

        return $value;
    }
}
