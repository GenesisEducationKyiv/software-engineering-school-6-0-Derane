<?php

declare(strict_types=1);

namespace App\Exception;

final class RepositoryNotFoundException extends \DomainException
{
    public function __construct(string $repository)
    {
        parent::__construct("Repository '{$repository}' not found on GitHub");
    }
}
