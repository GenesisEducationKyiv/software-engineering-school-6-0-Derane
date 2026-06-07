<?php

declare(strict_types=1);

namespace Tests\Integration\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\Pagination;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Tests\Integration\IntegrationTestCase;

final class PdoSubscriptionRepositoryTest extends IntegrationTestCase
{
    private SubscriptionRepository $repo;
    private SubscriptionCountPort $counts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->c->get(SubscriptionRepository::class);
        $this->counts = $this->c->get(SubscriptionCountPort::class);
    }

    public function testCreatePersistsRow(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $subscription = $this->subscribe($email, $repository);

        $this->assertSame($email, $subscription->email());
        $this->assertSame($repository, $subscription->repository());
        $this->assertGreaterThan(0, (int) $subscription->id());
        $this->assertNotEmpty($subscription->createdAt());
    }

    public function testCreateIsIdempotentOnDuplicate(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $first = $this->subscribe($email, $repository);
        $second = $this->subscribe($email, $repository);

        $this->assertSame($first->id(), $second->id());
        $this->assertCount(1, $this->repo->findAll(new Pagination(10, 0))->items);
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findById($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testFindByIdReturnsCreatedRow(): void
    {
        $created = $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $found = $this->repo->findById((int) $created->id());

        $this->assertNotNull($found);
        $this->assertSame($created->email(), $found->email());
        $this->assertSame($created->repository(), $found->repository());
    }

    public function testFindByEmailAndRepositoryReturnsCreatedRow(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();
        $created = $this->subscribe($email, $repository);

        $found = $this->repo->findByEmailAndRepository($email, $repository);

        $this->assertNotNull($found);
        $this->assertSame($created->id(), $found->id());
    }

    public function testFindByEmailAndRepositoryReturnsNullForMissing(): void
    {
        $this->assertNull(
            $this->repo->findByEmailAndRepository($this->faker->safeEmail(), $this->repoName())
        );
    }

    public function testFindByEmailFiltersAndPaginates(): void
    {
        $owner = $this->faker->safeEmail();
        $other = $this->faker->safeEmail();
        $repoA = $this->repoName();
        $repoB = $this->repoName();

        $this->subscribe($owner, $repoA);
        $this->subscribe($owner, $repoB);
        $this->subscribe($other, $this->repoName());

        $allForOwner = $this->repo->findByEmail($owner, new Pagination(10, 0));
        $this->assertSame(2, $allForOwner->total);
        $this->assertCount(2, $allForOwner->items);
        $repos = array_map(fn($s) => $s->repository(), $allForOwner->items);
        $this->assertContains($repoA, $repos);
        $this->assertContains($repoB, $repos);

        $firstPage = $this->repo->findByEmail($owner, new Pagination(1, 0));
        $secondPage = $this->repo->findByEmail($owner, new Pagination(1, 1));
        $this->assertCount(1, $firstPage->items);
        $this->assertCount(1, $secondPage->items);
        $this->assertNotSame($firstPage->items[0]->id(), $secondPage->items[0]->id());
        $this->assertTrue($firstPage->hasNextPage());
        $this->assertFalse($secondPage->hasNextPage());
    }

    public function testFindAllPaginates(): void
    {
        $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $page1 = $this->repo->findAll(new Pagination(2, 0));
        $page2 = $this->repo->findAll(new Pagination(2, 2));

        $this->assertCount(2, $page1->items);
        $this->assertCount(1, $page2->items);
        $this->assertSame(3, $page1->total);
        $this->assertTrue($page1->hasNextPage());
        $this->assertFalse($page2->hasNextPage());
    }

    public function testDeleteReturnsTrueWhenRowExisted(): void
    {
        $subscription = $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $this->assertTrue($this->repo->delete((int) $subscription->id()));
        $this->assertNull($this->repo->findById((int) $subscription->id()));
    }

    public function testDeleteReturnsFalseWhenRowMissing(): void
    {
        $this->assertFalse($this->repo->delete($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testFindSubscribersByRepositoryReturnsCollection(): void
    {
        $repository = $this->repoName();
        $first = $this->subscribe($this->faker->safeEmail(), $repository);
        $second = $this->subscribe($this->faker->safeEmail(), $repository);
        $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $subscribers = $this->repo->findSubscribersByRepository($repository);

        $ids = array_map(fn($ref) => $ref->id, iterator_to_array($subscribers));
        sort($ids);
        $expected = [(int) $first->id(), (int) $second->id()];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testCountAllReturnsTotalSubscriptions(): void
    {
        $before = $this->counts->countAll();

        $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $this->assertSame($before + 2, $this->counts->countAll());
    }

    private function subscribe(string $email, string $repository): Subscription
    {
        return $this->repo->create(Subscription::subscribe(
            new EmailAddress($email),
            new RepositoryName($repository),
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ));
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
