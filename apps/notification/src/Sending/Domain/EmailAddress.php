<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Self-validating recipient email. Constructing it IS the validation, so the
 * Sending domain never carries an unvalidated address — the wire mapper turns a
 * malformed value into a poison-message rejection at the boundary.
 */
final readonly class EmailAddress implements \Stringable
{
    public function __construct(private string $value)
    {
        if (filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email format');
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
