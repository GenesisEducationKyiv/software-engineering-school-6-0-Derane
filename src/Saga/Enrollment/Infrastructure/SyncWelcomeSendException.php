<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure;

/**
 * Throwing leaves the saga Started/AwaitingConfirmation for the next relay tick to retry;
 * a normal return from publish() means a definitive outcome (Sent|Failed) was applied in-thread.
 *
 * @psalm-api
 */
final class SyncWelcomeSendException extends \RuntimeException
{
    public static function transportExhausted(string $transport, int $attempts, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('welcome %s send failed after %d attempt(s)', $transport, $attempts),
            previous: $previous,
        );
    }

    public static function benignContention(string $transport, string $detail): self
    {
        return new self(
            sprintf('welcome %s send hit benign in-flight contention: %s', $transport, $detail),
        );
    }

    public static function nonRetryable(string $transport, string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('welcome %s send failed (non-retryable): %s', $transport, $detail),
            previous: $previous,
        );
    }
}
