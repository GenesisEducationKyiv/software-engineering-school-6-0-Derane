<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Outcome of NotificationLedger::claim(). A won claim carries a fencing
 * token that markSent()/recordFailedAttempt() must present — a worker whose
 * lease expired and was re-claimed can no longer touch the row.
 */
final readonly class ClaimResult
{
    private function __construct(
        public ClaimOutcome $outcome,
        private ?string $token,
    ) {
    }

    public static function claimed(string $token): self
    {
        return new self(ClaimOutcome::Claimed, $token);
    }

    public static function alreadySent(): self
    {
        return new self(ClaimOutcome::AlreadySent, null);
    }

    public static function inFlight(): self
    {
        return new self(ClaimOutcome::InFlight, null);
    }

    public function token(): string
    {
        if ($this->token === null) {
            throw new \LogicException('Only a won claim carries a fencing token');
        }

        return $this->token;
    }
}
