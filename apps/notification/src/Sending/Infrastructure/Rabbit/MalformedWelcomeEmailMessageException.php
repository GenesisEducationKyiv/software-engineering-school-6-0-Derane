<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * Thrown by {@see SendWelcomeEmailMessageMapper} when a message body cannot be
 * turned into a {@see \App\Sending\Domain\WelcomeEmail} — undecodable JSON, wrong
 * schema version, or a required field missing or of the wrong type.
 *
 * A dedicated type (not \JsonException / \TypeError) lets the consumer route a
 * fundamentally unprocessable "poison" message straight to the DLQ without
 * consulting the retry bound, distinct from an environmental failure.
 */
final class MalformedWelcomeEmailMessageException extends \RuntimeException
{
}
