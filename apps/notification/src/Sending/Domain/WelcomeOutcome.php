<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * The disposition of a processed welcome notification, echoed to the monolith
 * orchestrator as `WelcomeEmailOutcome/v1`. `Sent` confirms the subscription;
 * `Failed` (terminal) compensates it.
 */
enum WelcomeOutcome: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
