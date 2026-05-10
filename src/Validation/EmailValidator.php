<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

final class EmailValidator
{
    public function isValid(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function assertValid(string $email): void
    {
        if (!$this->isValid($email)) {
            throw new ValidationException('Invalid email format');
        }
    }
}
