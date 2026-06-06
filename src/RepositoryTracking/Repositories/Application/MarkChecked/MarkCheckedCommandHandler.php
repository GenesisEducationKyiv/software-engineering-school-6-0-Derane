<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\MarkChecked;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Write-side: records a scan-cycle heartbeat (no new release found). Creates a
 * transient RepositoryStatus aggregate to record the RepositoryChecked event,
 * persists via ScanProgressWriter, then dispatches the recorded events on the
 * PSR-14 plane for future listeners (metrics, observability).
 *
 * @implements CommandHandler<MarkCheckedCommand>
 *
 * @psalm-api
 */
final readonly class MarkCheckedCommandHandler implements CommandHandler
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
        $status->markChecked();

        $this->writer->markChecked($command->fullName);

        foreach ($status->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
