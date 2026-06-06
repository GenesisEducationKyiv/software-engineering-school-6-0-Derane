<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Application\MarkReleaseSeen;

use App\RepositoryTracking\Repositories\Application\MarkReleaseSeen\MarkReleaseSeenCommand;
use App\RepositoryTracking\Repositories\Application\MarkReleaseSeen\MarkReleaseSeenCommandHandler;
use App\RepositoryTracking\Repositories\Domain\ReleaseSeenAdvanced;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class MarkReleaseSeenCommandHandlerTest extends TestCase
{
    private ScanProgressWriter&MockObject $writer;
    /** @var list<object> */
    private array $dispatchedEvents = [];
    private MarkReleaseSeenCommandHandler $handler;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(ScanProgressWriter::class);
        $this->dispatchedEvents = [];

        $dispatcher = new class ($this->dispatchedEvents) implements EventDispatcherInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink)
            {
            }

            #[\Override]
            public function dispatch(object $event): object
            {
                $this->sink[] = $event;

                return $event;
            }
        };

        $this->handler = new MarkReleaseSeenCommandHandler($this->writer, $dispatcher);
    }

    public function testHandlerCallsMarkReleaseSeenAndDispatchesEvent(): void
    {
        $this->writer->expects($this->once())
            ->method('markReleaseSeen')
            ->with('owner/repo', 'v1.2.3');

        ($this->handler)(new MarkReleaseSeenCommand('owner/repo', 'v1.2.3'));

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertInstanceOf(ReleaseSeenAdvanced::class, $event);
        $this->assertSame('owner/repo', $event->repository);
        $this->assertSame('v1.2.3', $event->tag);
    }
}
