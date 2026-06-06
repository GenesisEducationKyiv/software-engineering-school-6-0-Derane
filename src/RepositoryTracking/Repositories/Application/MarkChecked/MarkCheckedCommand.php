<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\MarkChecked;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class MarkCheckedCommand implements Command
{
    public function __construct(public string $fullName)
    {
    }
}
