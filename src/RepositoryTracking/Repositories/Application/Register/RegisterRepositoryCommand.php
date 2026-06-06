<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\Register;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class RegisterRepositoryCommand implements Command
{
    public function __construct(public string $fullName)
    {
    }
}
