<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Immutable, self-validating value object for a GitHub repository name in
 * "owner/repo" form. Absorbs the rule enforced by RepositoryNameValidator.
 *
 * @psalm-api
 */
final readonly class RepositoryName implements \Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(private string $value)
    {
        if (!(bool) preg_match(self::PATTERN, $this->value)) {
            throw new InvalidArgumentException('Invalid repository format. Expected: owner/repo');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function owner(): string
    {
        return substr($this->value, 0, $this->slashPosition());
    }

    public function repo(): string
    {
        return substr($this->value, $this->slashPosition() + 1);
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

    /**
     * The regex enforced in the constructor guarantees exactly one slash with
     * non-empty sides, so strpos() always returns a valid integer position.
     */
    private function slashPosition(): int
    {
        $position = strpos($this->value, '/');

        return $position === false ? 0 : $position;
    }
}
