<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Port for the welcome-email relay publish. Contract: normal return = a DEFINITIVE disposition
 * was reached; a throw = NO definitive disposition, so the saga is NOT advanced and is left for
 * the next tick to retry.
 *
 * @psalm-api
 */
interface WelcomeEmailRelay
{
    public function publish(SendWelcomeEmail $message): void;
}
