<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Error;

/**
 * A welcome-email send request (gRPC message or REST JSON) carried a malformed or
 * missing field — a bad transport-level shape, or a present-but-invalid value the
 * {@see \App\Sending\Domain\EmailAddress}/{@see \App\Sending\Domain\RepositoryName}
 * value objects reject. Maps to gRPC INVALID_ARGUMENT / HTTP 400 via
 * {@see ExceptionStatusMap} (FR6/RD6).
 *
 * It is the notification-local validation exception the sync transport surfaces
 * share; the service has no Shared `ValidationException` of its own. It extends
 * \RuntimeException so it sits naturally inside the status map's catch ladder — and
 * is matched ahead of the generic \RuntimeException arm (load-bearing, RD6).
 */
final class WelcomeRequestValidationException extends \RuntimeException
{
}
