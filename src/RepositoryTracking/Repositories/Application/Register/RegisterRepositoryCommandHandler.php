<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\Register;

use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;

/**
 * Write-side: idempotently registers a repository in the scan registry. Delegates
 * directly to the TrackedRepositoryRegistrar port (INSERT … ON CONFLICT DO
 * NOTHING). No domain event is recorded — registration is a side-effect of
 * subscription, not a lifecycle event of RepositoryStatus itself.
 *
 * @implements CommandHandler<RegisterRepositoryCommand>
 *
 * @psalm-api
 */
final readonly class RegisterRepositoryCommandHandler implements CommandHandler
{
    public function __construct(private TrackedRepositoryRegistrar $registrar)
    {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $this->registrar->ensureExists($command->fullName);
    }
}
