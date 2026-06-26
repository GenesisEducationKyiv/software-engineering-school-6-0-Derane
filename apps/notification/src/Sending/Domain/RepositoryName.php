<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/** Self-validating "owner/repo" identifier. */
final readonly class RepositoryName implements \Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(private string $value)
    {
        if (!(bool) preg_match(self::PATTERN, $this->value)) {
            throw new \InvalidArgumentException('Invalid repository format. Expected: owner/repo');
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
