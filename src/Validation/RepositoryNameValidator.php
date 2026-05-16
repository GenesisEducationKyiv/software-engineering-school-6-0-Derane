<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

final readonly class RepositoryNameValidator
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function isValid(string $repository): bool
    {
        return (bool) preg_match(self::PATTERN, $repository);
    }

    public function assertValid(string $repository): void
    {
        if (!$this->isValid($repository)) {
            throw new ValidationException('Invalid repository format. Expected: owner/repo');
        }
    }
}
