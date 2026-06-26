<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Self-validating release tag. Tags are opaque: the only rule is non-empty /
 * non-whitespace-only, stored verbatim.
 */
final readonly class ReleaseTag implements \Stringable
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === '') {
            throw new \InvalidArgumentException('Invalid release tag: must not be empty');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
