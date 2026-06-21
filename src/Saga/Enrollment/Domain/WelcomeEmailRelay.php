<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Port for the outbox-style relay publish. Implemented by RabbitWelcomeEmailRelay
 * with publisher confirms; it throws on an unconfirmed publish so the saga is NOT
 * advanced (the relay re-tries next tick, after recordRelayFailure).
 *
 * @psalm-api
 */
interface WelcomeEmailRelay
{
    public function publish(SendWelcomeEmail $message): void;
}
