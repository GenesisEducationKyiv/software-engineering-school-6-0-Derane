<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * Thrown by SendReleaseEmailMessageMapper when a message body cannot be turned
 * into a ReleaseEmail — undecodable JSON, wrong schema version, or a required
 * field missing or of the wrong type.
 *
 * Using a dedicated exception type (rather than \JsonException or \TypeError)
 * lets SendReleaseEmailConsumer's catch block distinguish a fundamentally
 * unprocessable "poison" message from an environmental failure — poison
 * messages go straight to the DLQ without consulting the retry bound.
 */
final class MalformedReleaseEmailMessageException extends \RuntimeException
{
}
