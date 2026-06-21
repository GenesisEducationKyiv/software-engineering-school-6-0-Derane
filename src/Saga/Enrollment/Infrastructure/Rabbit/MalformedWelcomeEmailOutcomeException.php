<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rabbit;

/**
 * Thrown by {@see WelcomeEmailOutcomeMessageMapper} when a reply body cannot be
 * mapped to a {@see \App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand}
 * — undecodable JSON, wrong schema, an unknown outcome value, or a required field
 * missing/of the wrong type.
 *
 * The reply queue has no DLX of its own (arch §7): the consumer logs and
 * acks-and-drops a malformed reply (a nack(requeue:false) would discard it
 * anyway), and the timeout sweeper is the backstop. A dedicated type keeps that
 * disposition distinct from an environmental fault.
 */
final class MalformedWelcomeEmailOutcomeException extends \RuntimeException
{
}
