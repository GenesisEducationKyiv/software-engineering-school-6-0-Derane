<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\Subscribe;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Shared\Domain\TransactionManager;
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
    private EnrollmentSagaStarter&MockObject $sagaStarter;
    private TransactionManager $transactionManager;
    /** @var list<string> */
    private array $txCalls = [];
    /** @var list<object> */
    private array $dispatchedEvents = [];
    private SubscribeCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->trackedRepositories = $this->createMock(TrackedRepositoryRegistrar::class);
        $this->gitHub = $this->createMock(ReleaseSource::class);
        $this->sagaStarter = $this->createMock(EnrollmentSagaStarter::class);
        $this->dispatchedEvents = [];
        $this->txCalls = [];

        // Passthrough TransactionManager that records entry/exit so the test can
        // assert create() + start() ran INSIDE the closure, before dispatch.
        $this->transactionManager = new class ($this->txCalls) implements TransactionManager {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            #[\Override]
            public function transactional(callable $work): mixed
            {
                $this->sink[] = 'tx:begin';
                $result = $work();
                $this->sink[] = 'tx:commit';

                return $result;
            }
        };

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
            FrozenClock::at('2026-06-07T12:00:00+00:00'),
            $this->sagaStarter,
            $this->transactionManager
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

        // create() returns the reconstituted row WITH an id (insert RETURNING id).
        $this->repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Subscription::class))
            ->willReturnCallback(static fn(Subscription $s): Subscription => Subscription::reconstitute(
                123,
                new \App\Shared\Domain\ValueObject\EmailAddress($s->email()),
                new RepositoryName($s->repository()),
                $s->createdAt(),
                $s->status()
            ));

        // The saga is started with the captured create() id.
        $this->sagaStarter->expects($this->once())
            ->method('start')
            ->with(123);

        ($this->handler)(new SubscribeCommand('test@example.com', 'golang/go'));

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertInstanceOf(SubscriptionCreated::class, $event);
        $this->assertSame('test@example.com', $event->email);
        $this->assertSame('golang/go', $event->repository);
    }

    public function testCreateAndSagaStartRunInsideOneTransactionBeforeDispatch(): void
    {
        $this->gitHub->method('repositoryExists')->willReturn(true);

        $calls = &$this->txCalls;
        $this->repository->method('create')
            ->willReturnCallback(static function (Subscription $s) use (&$calls): Subscription {
                $calls[] = 'create';

                return Subscription::reconstitute(
                    7,
                    new \App\Shared\Domain\ValueObject\EmailAddress($s->email()),
                    new RepositoryName($s->repository()),
                    $s->createdAt(),
                    $s->status()
                );
            });
        $this->sagaStarter->method('start')
            ->willReturnCallback(static function (int $id) use (&$calls): void {
                $calls[] = 'start:' . $id;
            });

        ($this->handler)(new SubscribeCommand('test@example.com', 'golang/go'));

        // create() then start() BOTH inside one tx (begin ... commit), atomically,
        // and the PSR-14 dispatch happens after the commit.
        $this->assertSame(['tx:begin', 'create', 'start:7', 'tx:commit'], $this->txCalls);
        $this->assertCount(1, $this->dispatchedEvents);
    }

    public function testNullIdFromCreateThrowsAndStartsNoSaga(): void
    {
        $this->gitHub->method('repositoryExists')->willReturn(true);

        // A committed row always has an id; a null id is a programmer error.
        $this->repository->method('create')
            ->willReturnCallback(static fn(Subscription $s): Subscription => $s);

        $this->sagaStarter->expects($this->never())->method('start');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('null id');

        ($this->handler)(new SubscribeCommand('test@example.com', 'golang/go'));
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
