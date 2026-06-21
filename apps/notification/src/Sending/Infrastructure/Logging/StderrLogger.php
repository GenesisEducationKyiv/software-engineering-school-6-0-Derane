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
    private const SENSITIVE_KEY_PATTERN = '/(authorization|api[_-]?key|password|passwd|secret|token)/i';

    /** @var resource */
    private $stream;

    /** @param resource|null $stream defaults to php://stderr; injectable for tests */
    public function __construct($stream = null)
    {
        if ($stream === null) {
            // The STDERR constant is only defined in the CLI SAPI. Under the
            // built-in web server (php -S, the cli-server SAPI used to serve
            // /health + /metrics) it is undefined and `?? \STDERR` fatals.
            // php://stderr resolves the same stream in both SAPIs.
            $stream = \defined('STDERR') ? \STDERR : \fopen('php://stderr', 'w');
        }

        if ($stream === false) {
            throw new \RuntimeException('Unable to open php://stderr for logging');
        }

        $this->stream = $stream;
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
            'context' => $this->redactContext($context),
        ];

        $encoded = json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{"level":"error","service":"notification","message":"unencodable log record"}';
        }

        fwrite($this->stream, $encoded . "\n");
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private function redactContext(array $context): array
    {
        /** @var array<array-key, mixed> $redacted */
        $redacted = [];
        /** @psalm-suppress MixedAssignment — iterating array<array-key, mixed> yields mixed values */
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            /** @psalm-suppress MixedAssignment — $context values are intentionally mixed */
            $redacted[$key] = is_array($value) ? $this->redactContext($value) : $value;
        }

        return $redacted;
    }
}
