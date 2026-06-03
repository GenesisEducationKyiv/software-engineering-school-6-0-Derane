<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Immutable, self-validating value object for a present GitHub release tag.
 * Tags are opaque: the only rule is non-empty / non-whitespace-only. No format
 * regex and no normalization — the original value is stored verbatim.
 *
 * @psalm-api
 */
final readonly class ReleaseTag implements \Stringable
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('Invalid release tag: must not be empty');
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
