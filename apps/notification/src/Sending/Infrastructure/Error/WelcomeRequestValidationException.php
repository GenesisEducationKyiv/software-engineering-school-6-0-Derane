<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Error;

/**
 * Maps to gRPC INVALID_ARGUMENT / HTTP 400 via {@see ExceptionStatusMap}; must be
 * matched ahead of the generic \RuntimeException arm in the catch ladder.
 */
final class WelcomeRequestValidationException extends \RuntimeException
{
}
