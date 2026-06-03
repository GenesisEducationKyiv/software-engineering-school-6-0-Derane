<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Immutable, self-validating value object for an email address. Absorbs the rule
 * enforced by EmailValidator (filter_var FILTER_VALIDATE_EMAIL).
 *
 * @psalm-api
 */
final readonly class EmailAddress implements \Stringable
{
    public function __construct(private string $value)
    {
        if (filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
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
