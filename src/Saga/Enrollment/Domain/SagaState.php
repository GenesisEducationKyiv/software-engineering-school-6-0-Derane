<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * The persisted lifecycle of an enrollment saga. Mirrors the PRD saga lifecycle:
 *
 *  Started               not yet published (the FR4 publish-state marker, "off")
 *  AwaitingConfirmation  published + publish-confirmed, awaiting reply ("on")
 *  Completed             terminal: WelcomeEmailOutcome{sent} -> subscription confirmed
 *  Compensating          transient: terminal failure / timeout -> cancelling
 *  Compensated           terminal: subscription cancelled
 *
 * Started-vs-AwaitingConfirmation IS the publish-state marker — no separate
 * boolean is needed.
 *
 * @psalm-api
 */
enum SagaState: string
{
    case Started = 'started';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case Compensating = 'compensating';
    case Compensated = 'compensated';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Compensated;
    }

    /**
     * The FR4 publish-state marker: true once the relay has confirmed the publish
     * and advanced the saga to AwaitingConfirmation.
     */
    public function isPublished(): bool
    {
        return $this === self::AwaitingConfirmation;
    }
}
