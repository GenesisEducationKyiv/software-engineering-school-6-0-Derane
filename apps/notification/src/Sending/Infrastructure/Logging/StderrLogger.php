<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Logging;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 stderr logger. Hand-rolled instead of pulling in monolog:
 * the service deliberately shares no code with the monolith and keeps its
 * dependency footprint to what the runtime actually needs.
 *
 * Not readonly only because AbstractLogger is a non-readonly parent.
 *
 * @psalm-api
 */
final class StderrLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream defaults to STDERR; injectable for tests */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? \STDERR;
    }

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite($this->stream, sprintf(
            "[%s] notification.%s: %s%s\n",
            gmdate(\DateTimeInterface::RFC3339),
            is_scalar($level) ? (string) $level : 'unknown',
            (string) $message,
            $this->encodeContext($context),
        ));
    }

    /** @param array<array-key, mixed> $context */
    private function encodeContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $encoded = json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return ' ' . ($encoded === false ? '<uncodable>' : $encoded);
    }
}
