<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Application\GetDueForScan;

use App\RepositoryTracking\Repositories\Application\GetDueForScan\DueRepositoriesResponse;
use App\RepositoryTracking\Repositories\Application\GetDueForScan\GetDueForScanHandler;
use App\RepositoryTracking\Repositories\Application\GetDueForScan\GetDueForScanQuery;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GetDueForScanHandlerTest extends TestCase
{
    private ScanCandidateSource&MockObject $candidates;
    private GetDueForScanHandler $handler;

    protected function setUp(): void
    {
        $this->candidates = $this->createMock(ScanCandidateSource::class);
        $this->handler = new GetDueForScanHandler($this->candidates);
    }

    public function testHandlerReturnsDueRepositories(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(10)
            ->willReturn(['owner/a', 'owner/b']);

        $response = ($this->handler)(new GetDueForScanQuery(10));

        $this->assertInstanceOf(DueRepositoriesResponse::class, $response);
        $this->assertSame(['owner/a', 'owner/b'], $response->repositories);
    }

    public function testHandlerReturnsEmptyListWhenNoDueRepositories(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(5)
            ->willReturn([]);

        $response = ($this->handler)(new GetDueForScanQuery(5));

        $this->assertInstanceOf(DueRepositoriesResponse::class, $response);
        $this->assertSame([], $response->repositories);
    }
}
