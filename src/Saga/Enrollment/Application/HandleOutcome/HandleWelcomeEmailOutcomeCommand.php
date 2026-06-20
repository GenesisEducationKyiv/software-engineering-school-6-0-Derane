<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\HandleOutcome;

use App\Shared\Domain\Bus\Command\Command;

/**
 * The reply-path CQRS command, built by the reply consumer from a
 * WelcomeEmailOutcome message and dispatched through the InMemoryCommandBus.
 *
 * Carries the primitive correlation keys only (sagaId string, subscriptionId
 * int) plus the outcome — no foreign value object crosses into Saga.Application.
 *
 * @psalm-api
 */
final readonly class HandleWelcomeEmailOutcomeCommand implements Command
{
    public function __construct(
        public string $sagaId,
        public int $subscriptionId,
        public WelcomeOutcome $outcome
    ) {
    }
}
