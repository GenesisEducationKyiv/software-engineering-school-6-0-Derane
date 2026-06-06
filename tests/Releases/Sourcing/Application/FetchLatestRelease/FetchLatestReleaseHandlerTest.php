<?php

declare(strict_types=1);

namespace Tests\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseHandler;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseQuery;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseResponse;
use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** @psalm-api */
final class FetchLatestReleaseHandlerTest extends TestCase
{
    private ReleaseSource&MockObject $source;
    private FetchLatestReleaseHandler $handler;

    protected function setUp(): void
    {
        $this->source = $this->createMock(ReleaseSource::class);
        $this->handler = new FetchLatestReleaseHandler($this->source);
    }

    public function testReturnsResponseWithReleaseWhenSourceReturnsRelease(): void
    {
        $release = new Release(
            'v1.0.0',
            'First Release',
            'https://github.com/acme/tool/releases/v1.0.0',
            '2026-01-01',
            'notes'
        );

        $this->source->expects($this->once())
            ->method('getLatestRelease')
            ->with('acme/tool')
            ->willReturn($release);

        $query = new FetchLatestReleaseQuery('acme/tool');
        $response = ($this->handler)($query);

        $this->assertInstanceOf(FetchLatestReleaseResponse::class, $response);
        $this->assertSame($release, $response->release);
    }

    public function testReturnsResponseWithNullWhenSourceReturnsNull(): void
    {
        $this->source->expects($this->once())
            ->method('getLatestRelease')
            ->with('acme/unknown')
            ->willReturn(null);

        $query = new FetchLatestReleaseQuery('acme/unknown');
        $response = ($this->handler)($query);

        $this->assertInstanceOf(FetchLatestReleaseResponse::class, $response);
        $this->assertNull($response->release);
    }
}
