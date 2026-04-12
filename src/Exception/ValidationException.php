<?php

declare(strict_types=1);

namespace App\Exception;

class ValidationException extends \DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
