<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\MarkReleaseSeen;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Write-side: advances the last-seen-tag marker for a repository after a new
 * release is detected. Creates a transient RepositoryStatus aggregate to record
 * the ReleaseSeenAdvanced domain event, persists via ScanProgressWriter, then
 * dispatches the recorded events on the PSR-14 plane.
 *
 * @implements CommandHandler<MarkReleaseSeenCommand>
 *
 * @psalm-api
 */
final readonly class MarkReleaseSeenCommandHandler implements CommandHandler
{
    public function __construct(
        private ScanProgressWriter $writer,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $status = RepositoryStatus::existing($command->fullName);
        $status->markReleaseSeen($command->tag);

        $this->writer->markReleaseSeen($command->fullName, $command->tag);

        foreach ($status->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
