<?php

declare(strict_types=1);

namespace Tests\Scanning\Scanner\Application\ScanReleases;

use App\Releases\Sourcing\Domain\NewReleaseDetected;
use App\Releases\Sourcing\Domain\RateLimitException;
use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Scanning\Scanner\Application\ReleaseDetector;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesHandler;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

final class ScanReleasesHandlerTest extends TestCase
{
    private ScanCandidateSource&MockObject $candidates;
    private ScanProgressWriter&MockObject $progress;
    private RepositoryStatusReader&MockObject $statusReader;
    private ReleaseSource&MockObject $gitHub;
    /** @var list<object> */
    private array $sequence = [];
    /** @var list<NewReleaseDetected> */
    private array $dispatchedEvents = [];
    private EventDispatcherInterface $eventDispatcher;
    private ScanReleasesHandler $handler;

    protected function setUp(): void
    {
        $this->candidates = $this->createMock(ScanCandidateSource::class);
        $this->progress = $this->createMock(ScanProgressWriter::class);
        $this->statusReader = $this->createMock(RepositoryStatusReader::class);
        $this->gitHub = $this->createMock(ReleaseSource::class);
        $this->sequence = [];
        $this->dispatchedEvents = [];

        $this->eventDispatcher = $this->recordingEventDispatcher();

        $this->handler = $this->buildHandler($this->eventDispatcher);
    }

    /**
     * Recording test double mirroring SubscribeCommandHandlerTest's anonymous
     * EventDispatcherInterface (appends dispatched events to $this->dispatchedEvents)
     * — extended here to also push a sequence marker into $this->sequence each
     * time dispatch() runs, so call-ordering relative to markReleaseSeen (which
     * pushes its own marker via willReturnCallback on $this->progress, see
     * below) can be asserted without introducing a new ordering idiom.
     */
    private function recordingEventDispatcher(): EventDispatcherInterface
    {
        return new class ($this->dispatchedEvents, $this->sequence) implements EventDispatcherInterface {
            /**
             * @param list<object> $eventSink
             * @param list<object> $sequenceSink
             */
            public function __construct(private array &$eventSink, private array &$sequenceSink)
            {
            }

            #[\Override]
            public function dispatch(object $event): object
            {
                $this->eventSink[] = $event;
                $this->sequenceSink[] = $event;

                return $event;
            }
        };
    }

    private function buildHandler(EventDispatcherInterface $eventDispatcher): ScanReleasesHandler
    {
        return new ScanReleasesHandler(
            $this->candidates,
            $this->progress,
            new ReleaseDetector($this->gitHub, $this->statusReader, new NullLogger()),
            $eventDispatcher,
            new NullLogger()
        );
    }

    private function release(string $tag, string $name = 'Release', string $body = 'notes'): Release
    {
        return new Release($tag, $name, "https://github.com/x/y/releases/tag/{$tag}", '2024-01-01', $body);
    }

    public function testHandlerFindsNewReleaseAndMarksItSeenAfterDispatchingEvent(): void
    {
        $release = $this->release('v1.22.0', 'Go 1.22');

        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($release);

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->with('golang/go')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.21.0', null));

        // Record a sequence marker each time markReleaseSeen runs, into the
        // SAME $sequence array the recording event-dispatcher pushes into —
        // the relative order of the two markers proves NewReleaseDetected was
        // dispatched strictly BEFORE the marker advanced.
        $this->progress->expects($this->once())
            ->method('markReleaseSeen')
            ->with('golang/go', 'v1.22.0')
            ->willReturnCallback(function () {
                $this->sequence[] = new \stdClass();
            });

        $this->handler->__invoke(new ScanReleasesCommand());

        self::assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        self::assertInstanceOf(NewReleaseDetected::class, $event);
        self::assertTrue($event->repository->equals(new RepositoryName('golang/go')));
        self::assertSame($release, $event->release);

        self::assertCount(2, $this->sequence, 'expected one NewReleaseDetected dispatch and one markReleaseSeen call');
        self::assertInstanceOf(NewReleaseDetected::class, $this->sequence[0]);
        self::assertInstanceOf(\stdClass::class, $this->sequence[1]);
    }

    public function testHandlerNoNewRelease(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($this->release('v1.21.0'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.21.0', null));

        $this->progress->expects($this->once())
            ->method('markChecked')
            ->with('golang/go');

        $this->progress->expects($this->never())->method('markReleaseSeen');

        $this->handler->__invoke(new ScanReleasesCommand());

        self::assertCount(0, $this->dispatchedEvents);
    }

    public function testHandlerNoReleases(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['some/repo']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('some/repo')
            ->willReturn(null);

        $this->progress->expects($this->once())
            ->method('markChecked')
            ->with('some/repo');

        $this->handler->__invoke(new ScanReleasesCommand());
        self::assertCount(0, $this->dispatchedEvents);
    }

    public function testHandlerHandlesRateLimit(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willThrowException(new RateLimitException('60'));

        $this->handler->__invoke(new ScanReleasesCommand());
        self::assertCount(0, $this->dispatchedEvents);
    }

    public function testDoesNotMarkTheReleaseSeenWhenTheNewReleaseDetectedDispatchThrows(): void
    {
        $release = $this->release('v1.22.0', 'Go 1.22');

        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($release);

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->with('golang/go')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.21.0', null));

        // A throwing dispatcher mirrors WhenNewReleaseDetectedThenPublishReleaseEmails
        // letting a publish() failure propagate uncaught — the load-bearing
        // outbox-free guarantee: the exception must reach __invoke()'s
        // per-repository catch (\Exception $e) (logged as "Scan error", loop
        // continues) WITHOUT markReleaseSeen ever being called.
        $throwingDispatcher = new class implements EventDispatcherInterface {
            #[\Override]
            public function dispatch(object $event): object
            {
                throw new \RuntimeException('publish failed (simulated)');
            }
        };

        $handler = $this->buildHandler($throwingDispatcher);

        $this->progress->expects($this->never())->method('markReleaseSeen');
        $this->progress->expects($this->never())->method('markChecked');

        // __invoke()'s existing per-repo catch (\Exception $e) swallows the
        // exception (logs "Scan error", continue) — so the handler completes
        // normally rather than throwing out of __invoke().
        $handler->__invoke(new ScanReleasesCommand());
    }
}
