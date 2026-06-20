<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\HandleOutcome;

/**
 * The two terminal outcomes a WelcomeEmailOutcome reply can carry. String-backed
 * so it maps directly onto the wire `outcome` field ("sent" | "failed").
 *
 * @psalm-api
 */
enum WelcomeOutcome: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
