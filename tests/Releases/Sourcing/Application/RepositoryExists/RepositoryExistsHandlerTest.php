<?php

declare(strict_types=1);

namespace Tests\Releases\Sourcing\Application\RepositoryExists;

use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsHandler;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsQuery;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsResponse;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** @psalm-api */
final class RepositoryExistsHandlerTest extends TestCase
{
    private ReleaseSource&MockObject $source;
    private RepositoryExistsHandler $handler;

    protected function setUp(): void
    {
        $this->source = $this->createMock(ReleaseSource::class);
        $this->handler = new RepositoryExistsHandler($this->source);
    }

    public function testReturnsTrueWhenRepositoryExists(): void
    {
        $this->source->expects($this->once())
            ->method('repositoryExists')
            ->with(new RepositoryName('golang/go'))
            ->willReturn(true);

        $query = new RepositoryExistsQuery('golang/go');
        $response = ($this->handler)($query);

        $this->assertInstanceOf(RepositoryExistsResponse::class, $response);
        $this->assertTrue($response->exists);
    }

    public function testReturnsFalseWhenRepositoryDoesNotExist(): void
    {
        $this->source->expects($this->once())
            ->method('repositoryExists')
            ->with(new RepositoryName('nonexistent/repo'))
            ->willReturn(false);

        $query = new RepositoryExistsQuery('nonexistent/repo');
        $response = ($this->handler)($query);

        $this->assertInstanceOf(RepositoryExistsResponse::class, $response);
        $this->assertFalse($response->exists);
    }
}
