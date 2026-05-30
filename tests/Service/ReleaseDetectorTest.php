<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Application\Event\Factory\ApplicationEventFactory;
use App\Application\Event\ReleaseDetected;
use App\Domain\Release;
use App\Domain\RepositoryStatus;
use App\Repository\RepositoryStatusReader;
use App\Service\GitHubServiceInterface;
use App\Service\ReleaseDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingEventPublisher;

class ReleaseDetectorTest extends TestCase
{
    private GitHubServiceInterface&MockObject $gitHub;
    private RepositoryStatusReader&MockObject $status;
    private RecordingEventPublisher $events;
    private ReleaseDetector $detector;

    protected function setUp(): void
    {
        $this->gitHub = $this->createMock(GitHubServiceInterface::class);
        $this->status = $this->createMock(RepositoryStatusReader::class);
        $this->events = new RecordingEventPublisher();
        $this->detector = new ReleaseDetector(
            $this->gitHub,
            $this->status,
            $this->events,
            new ApplicationEventFactory()
        );
    }

    private function release(string $tag): Release
    {
        return new Release($tag, 'Release', "https://github.com/x/y/releases/tag/{$tag}", '2024-01-01', 'notes');
    }

    public function testEmitsReleaseDetectedAndReturnsReleaseWhenTagIsNew(): void
    {
        $this->gitHub->method('getLatestRelease')->with('golang/go')->willReturn($this->release('v1.22'));
        $this->status->method('getStatus')
            ->with('golang/go')
            ->willReturn(new RepositoryStatus('golang/go', 'v1.21', null));

        $result = $this->detector->detect('golang/go');

        $this->assertNotNull($result);
        $this->assertSame('v1.22', $result->tagName);

        $detected = $this->events->ofType(ReleaseDetected::class);
        $this->assertCount(1, $detected);
        $this->assertSame('golang/go', $detected[0]->repository);
        $this->assertSame('v1.22', $detected[0]->tag);
        $this->assertSame('v1.21', $detected[0]->previousTag);
    }

    public function testFirstReleaseCarriesNullPreviousTag(): void
    {
        $this->gitHub->method('getLatestRelease')->willReturn($this->release('v1.0'));
        $this->status->method('getStatus')->willReturn(new RepositoryStatus('golang/go', null, null));

        $this->detector->detect('golang/go');

        $detected = $this->events->ofType(ReleaseDetected::class);
        $this->assertCount(1, $detected);
        $this->assertNull($detected[0]->previousTag);
    }

    public function testReturnsNullAndEmitsNothingWhenTagUnchanged(): void
    {
        $this->gitHub->method('getLatestRelease')->willReturn($this->release('v1.22'));
        $this->status->method('getStatus')->willReturn(new RepositoryStatus('golang/go', 'v1.22', null));

        $this->assertNull($this->detector->detect('golang/go'));
        $this->assertCount(0, $this->events->ofType(ReleaseDetected::class));
    }

    public function testReturnsNullAndEmitsNothingWhenThereIsNoRelease(): void
    {
        $this->gitHub->method('getLatestRelease')->willReturn(null);

        $this->assertNull($this->detector->detect('golang/go'));
        $this->assertCount(0, $this->events->ofType(ReleaseDetected::class));
    }

    public function testReturnsNullWhenLatestReleaseHasNoTag(): void
    {
        $this->gitHub->method('getLatestRelease')->willReturn(
            new Release(null, 'untagged', 'https://github.com/x/y', '2024-01-01', 'notes')
        );

        $this->assertNull($this->detector->detect('golang/go'));
        $this->assertCount(0, $this->events->ofType(ReleaseDetected::class));
    }
}
