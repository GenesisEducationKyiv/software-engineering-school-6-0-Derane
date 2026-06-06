<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Infrastructure\Cli;

use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Shared\Domain\Bus\Command\CommandBus;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class ScannerCliRunner
{
    public function __construct(
        private CommandBus $bus,
        private LoggerInterface $logger,
        private int $interval
    ) {
    }

    public function run(): void
    {
        $this->logger->info("Scanner started. Checking every {$this->interval} seconds.");
        while (true) {
            $this->bus->dispatch(new ScanReleasesCommand());
            sleep($this->interval);
        }
    }
}
