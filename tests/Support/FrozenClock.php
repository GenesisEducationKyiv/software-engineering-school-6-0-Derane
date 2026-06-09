<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Domain\Clock;

/** Test double: always reports the same instant. */
final readonly class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $frozenAt)
    {
    }

    public static function at(string $instant): self
    {
        return new self(new \DateTimeImmutable($instant));
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->frozenAt;
    }
}
