<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Application\MarkChecked;

use App\RepositoryTracking\Repositories\Application\MarkChecked\MarkCheckedCommand;
use App\RepositoryTracking\Repositories\Application\MarkChecked\MarkCheckedCommandHandler;
use App\RepositoryTracking\Repositories\Domain\RepositoryChecked;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class MarkCheckedCommandHandlerTest extends TestCase
{
    private ScanProgressWriter&MockObject $writer;
    /** @var list<object> */
    private array $dispatchedEvents = [];
    private MarkCheckedCommandHandler $handler;

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

        $this->handler = new MarkCheckedCommandHandler($this->writer, $dispatcher);
    }

    public function testHandlerCallsMarkCheckedAndDispatchesEvent(): void
    {
        $this->writer->expects($this->once())
            ->method('markChecked')
            ->with('owner/repo');

        ($this->handler)(new MarkCheckedCommand('owner/repo'));

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertInstanceOf(RepositoryChecked::class, $event);
        $this->assertSame('owner/repo', $event->repository);
    }
}
