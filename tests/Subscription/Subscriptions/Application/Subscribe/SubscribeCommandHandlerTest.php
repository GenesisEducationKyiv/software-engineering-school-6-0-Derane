<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\Subscribe;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Tests\Support\FrozenClock;

final class SubscribeCommandHandlerTest extends TestCase
{
    private SubscriptionRepository&MockObject $repository;
    private TrackedRepositoryRegistrar&MockObject $trackedRepositories;
    private ReleaseSource&MockObject $gitHub;
    /** @var list<object> */
    private array $dispatchedEvents = [];
    private SubscribeCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->trackedRepositories = $this->createMock(TrackedRepositoryRegistrar::class);
        $this->gitHub = $this->createMock(ReleaseSource::class);
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

        $this->handler = new SubscribeCommandHandler(
            $this->repository,
            $this->gitHub,
            $this->trackedRepositories,
            $dispatcher,
            FrozenClock::at('2026-06-07T12:00:00+00:00')
        );
    }

    public function testSubscribeSuccessEnsuresTrackedCreatesAndDispatchesEvent(): void
    {
        $this->gitHub->expects($this->once())
            ->method('repositoryExists')
            ->with(new RepositoryName('golang/go'))
            ->willReturn(true);

        $this->trackedRepositories->expects($this->once())
            ->method('ensureExists')
            ->with('golang/go');

        $this->repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Subscription::class))
            ->willReturnCallback(static fn(Subscription $s): Subscription => $s);

        ($this->handler)(new SubscribeCommand('test@example.com', 'golang/go'));

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertInstanceOf(SubscriptionCreated::class, $event);
        $this->assertSame('test@example.com', $event->email);
        $this->assertSame('golang/go', $event->repository);
    }

    public function testSubscribeInvalidEmailThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        ($this->handler)(new SubscribeCommand('not-an-email', 'golang/go'));
    }

    public function testSubscribeInvalidRepositoryThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid repository format. Expected: owner/repo');

        ($this->handler)(new SubscribeCommand('test@example.com', 'invalid-repo'));
    }

    public function testEmailViolationWinsWhenBothFieldsAreInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        ($this->handler)(new SubscribeCommand('not-an-email', 'invalid-repo'));
    }

    public function testSubscribeRepositoryNotFoundThrows(): void
    {
        $this->gitHub->expects($this->once())
            ->method('repositoryExists')
            ->with(new RepositoryName('nonexistent/repo'))
            ->willReturn(false);

        $this->expectException(RepositoryNotFoundException::class);

        ($this->handler)(new SubscribeCommand('test@example.com', 'nonexistent/repo'));
    }
}
