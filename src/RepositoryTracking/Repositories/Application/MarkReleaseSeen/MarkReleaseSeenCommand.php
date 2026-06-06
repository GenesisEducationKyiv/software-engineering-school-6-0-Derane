<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\MarkReleaseSeen;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class MarkReleaseSeenCommand implements Command
{
    public function __construct(
        public string $fullName,
        public string $tag,
    ) {
    }
}
