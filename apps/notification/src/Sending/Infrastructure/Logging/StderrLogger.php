<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Logging;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 stderr logger emitting one structured JSON object per line
 * (timestamp, level, service, message, context) for log aggregation. Hand-rolled
 * instead of pulling in monolog: the service deliberately shares no code with the
 * monolith and keeps its dependency footprint to what the runtime actually needs.
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
        $record = [
            'timestamp' => gmdate(\DateTimeInterface::RFC3339),
            'level' => is_scalar($level) ? (string) $level : 'unknown',
            'service' => 'notification',
            'message' => (string) $message,
            'context' => $context,
        ];

        $encoded = json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{"level":"error","service":"notification","message":"unencodable log record"}';
        }

        fwrite($this->stream, $encoded . "\n");
    }
}
