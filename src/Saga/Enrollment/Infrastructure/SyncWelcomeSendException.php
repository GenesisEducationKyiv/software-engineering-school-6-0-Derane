<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure;

/**
 * Raised by the synchronous welcome relays (REST/gRPC) when the send call does NOT
 * yield a definitive business outcome — i.e. transport failure after the bounded
 * retry budget is spent, benign in-flight contention (gRPC ABORTED / REST 409), or a
 * validation/internal error. It is the sync-path analogue of
 * RabbitPublishFailedException: a throw means the saga must be left
 * Started/AwaitingConfirmation for the next relay tick to retry (RD6). A normal return
 * from publish() means a definitive outcome (Sent|Failed) was applied in-thread.
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
