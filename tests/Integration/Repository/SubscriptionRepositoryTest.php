<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Config\Pagination;
use App\Repository\SubscriptionRepositoryInterface;
use Tests\Integration\IntegrationTestCase;

final class SubscriptionRepositoryTest extends IntegrationTestCase
{
    private SubscriptionRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->c->get(SubscriptionRepositoryInterface::class);
    }

    public function testCreatePersistsRow(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $subscription = $this->repo->create($email, $repository);

        $this->assertSame($email, $subscription->email);
        $this->assertSame($repository, $subscription->repository);
        $this->assertGreaterThan(0, $subscription->id);
        $this->assertNotEmpty($subscription->createdAt);
    }

    public function testCreateIsIdempotentOnDuplicate(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $first = $this->repo->create($email, $repository);
        $second = $this->repo->create($email, $repository);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->repo->findAll(new Pagination(10, 0))->items);
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findById($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testFindByIdReturnsCreatedRow(): void
    {
        $created = $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $found = $this->repo->findById($created->id);

        $this->assertNotNull($found);
        $this->assertSame($created->email, $found->email);
        $this->assertSame($created->repository, $found->repository);
    }

    public function testFindByEmailFiltersAndPaginates(): void
    {
        $owner = $this->faker->safeEmail();
        $other = $this->faker->safeEmail();
        $repoA = $this->repoName();
        $repoB = $this->repoName();

        $this->repo->create($owner, $repoA);
        $this->repo->create($owner, $repoB);
        $this->repo->create($other, $this->repoName());

        $allForOwner = $this->repo->findByEmail($owner, new Pagination(10, 0));
        $this->assertSame(2, $allForOwner->total);
        $this->assertCount(2, $allForOwner->items);
        $repos = array_map(fn($s) => $s->repository, $allForOwner->items);
        $this->assertContains($repoA, $repos);
        $this->assertContains($repoB, $repos);

        $firstPage = $this->repo->findByEmail($owner, new Pagination(1, 0));
        $secondPage = $this->repo->findByEmail($owner, new Pagination(1, 1));
        $this->assertCount(1, $firstPage->items);
        $this->assertCount(1, $secondPage->items);
        $this->assertNotSame($firstPage->items[0]->id, $secondPage->items[0]->id);
        $this->assertTrue($firstPage->hasNextPage());
        $this->assertFalse($secondPage->hasNextPage());
    }

    public function testFindAllPaginates(): void
    {
        $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $this->repo->create($this->faker->safeEmail(), $this->repoName());

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
        $subscription = $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $this->assertTrue($this->repo->delete($subscription->id));
        $this->assertNull($this->repo->findById($subscription->id));
    }

    public function testDeleteReturnsFalseWhenRowMissing(): void
    {
        $this->assertFalse($this->repo->delete($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testFindSubscribersByRepositoryReturnsCollection(): void
    {
        $repository = $this->repoName();
        $first = $this->repo->create($this->faker->safeEmail(), $repository);
        $second = $this->repo->create($this->faker->safeEmail(), $repository);
        $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $subscribers = $this->repo->findSubscribersByRepository($repository);

        $ids = array_map(fn($ref) => $ref->id, iterator_to_array($subscribers));
        sort($ids);
        $expected = [$first->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
