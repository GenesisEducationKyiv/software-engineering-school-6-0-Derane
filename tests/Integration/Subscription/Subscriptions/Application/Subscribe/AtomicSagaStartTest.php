<?php

declare(strict_types=1);

namespace Tests\Integration\Subscription\Subscriptions\Application\Subscribe;

use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Saga\Enrollment\Domain\SagaState;
use App\Shared\Domain\TransactionManager;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Real-Postgres atomic start (FR1/FR2/FR3): the subscription and its saga are
 * written in one transaction, a duplicate POST starts no second saga, and a forced
 * failure between the two writes rolls back BOTH (the precondition that create()
 * opens no inner transaction).
 */
final class AtomicSagaStartTest extends IntegrationTestCase
{
    private SubscriptionRepository $repository;
    private EnrollmentSagaReader $sagaReader;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->c->get(SubscriptionRepository::class);
        $this->sagaReader = $this->c->get(EnrollmentSagaReader::class);
        $this->pdo = $this->c->get(PDO::class);
        $this->pdo->exec('TRUNCATE enrollment_sagas RESTART IDENTITY');
    }

    public function testSubscribeWritesSubscriptionAndSagaAtomicallyAsStarted(): void
    {
        $handler = $this->c->get(SubscribeCommandHandler::class);

        $handler(new SubscribeCommand($this->faker->safeEmail(), $this->repoName()));

        $all = $this->repository->findAll(new \App\Shared\Domain\ValueObject\Pagination(10, 0));
        $this->assertCount(1, $all->items);
        $subscription = $all->items[0];
        $this->assertSame('pending', $subscription->status());

        $saga = $this->sagaReader->findBySubscriptionId((int) $subscription->id());
        $this->assertNotNull($saga);
        $this->assertSame(SagaState::Started, $saga->state());
        $this->assertSame((int) $subscription->id(), $saga->subscriptionId());
    }

    public function testDuplicateSubscribeStartsNoSecondSaga(): void
    {
        $handler = $this->c->get(SubscribeCommandHandler::class);
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $handler(new SubscribeCommand($email, $repository));
        $handler(new SubscribeCommand($email, $repository));

        $subscription = $this->repository->findByEmailAndRepository($email, $repository);
        $this->assertNotNull($subscription);

        $this->assertSame(1, $this->countSagas());
        $this->assertSame(1, $this->countSubscriptions($email, $repository));
    }

    public function testForcedFailureBetweenWritesRollsBackBoth(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        // Handler with a saga starter that throws AFTER the subscription INSERT — the
        // outer transaction must roll back both writes (create() runs no inner commit).
        $throwingStarter = new class implements EnrollmentSagaStarter {
            #[\Override]
            public function start(int $subscriptionId): void
            {
                throw new \RuntimeException('forced failure between subscription and saga INSERT');
            }
        };

        $handler = new SubscribeCommandHandler(
            $this->repository,
            $this->c->get(\App\Releases\Sourcing\Domain\ReleaseSource::class),
            $this->c->get(\App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar::class),
            $this->c->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
            $this->c->get(\App\Shared\Domain\Clock::class),
            $throwingStarter,
            $this->c->get(TransactionManager::class)
        );

        try {
            $handler(new SubscribeCommand($email, $repository));
            $this->fail('Expected the forced failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forced failure', $e->getMessage());
        }

        // Neither the subscription nor the saga is committed.
        $this->assertSame(0, $this->countSubscriptions($email, $repository));
        $this->assertSame(0, $this->countSagas());
        $this->assertFalse($this->pdo->inTransaction());
    }

    private function countSagas(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM enrollment_sagas')->fetchColumn();
    }

    private function countSubscriptions(string $email, string $repository): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM subscriptions WHERE email = :email AND repository = :repository'
        );
        $stmt->execute([':email' => $email, ':repository' => $repository]);

        return (int) $stmt->fetchColumn();
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
