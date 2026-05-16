<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

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

    public function testCreatePersistsRowAndAutoRegistersRepository(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $row = $this->repo->create($email, $repository);

        $this->assertSame($email, $row['email']);
        $this->assertSame($repository, $row['repository']);
        $this->assertGreaterThan(0, $row['id']);
        $this->assertNotEmpty($row['created_at']);

        $info = $this->repo->getRepositoryInfo($repository);
        $this->assertNotNull($info, 'create() should register repository');
        $this->assertSame($repository, $info['full_name']);
    }

    public function testCreateIsIdempotentOnDuplicate(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $first = $this->repo->create($email, $repository);
        $second = $this->repo->create($email, $repository);

        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $this->repo->findAll());
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findById($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testFindByIdReturnsCreatedRow(): void
    {
        $created = $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $found = $this->repo->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['email'], $found['email']);
        $this->assertSame($created['repository'], $found['repository']);
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

        $ownerRows = $this->repo->findByEmail($owner);
        $this->assertCount(2, $ownerRows);
        $repos = array_column($ownerRows, 'repository');
        $this->assertContains($repoA, $repos);
        $this->assertContains($repoB, $repos);

        $firstPage = $this->repo->findByEmail($owner, 1, 0);
        $secondPage = $this->repo->findByEmail($owner, 1, 1);
        $this->assertCount(1, $firstPage);
        $this->assertCount(1, $secondPage);
        $this->assertNotSame($firstPage[0]['id'], $secondPage[0]['id']);
    }

    public function testFindAllPaginates(): void
    {
        $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $this->assertCount(2, $this->repo->findAll(2, 0));
        $this->assertCount(1, $this->repo->findAll(2, 2));
    }

    public function testDeleteReturnsTrueWhenRowExisted(): void
    {
        $row = $this->repo->create($this->faker->safeEmail(), $this->repoName());

        $this->assertTrue($this->repo->delete($row['id']));
        $this->assertNull($this->repo->findById($row['id']));
    }

    public function testDeleteReturnsFalseWhenRowMissing(): void
    {
        $this->assertFalse($this->repo->delete($this->faker->numberBetween(1_000_000, 9_999_999)));
    }

    public function testGetActiveRepositoriesReturnsDistinctList(): void
    {
        $shared = $this->repoName();
        $unique = $this->repoName();

        $this->repo->create($this->faker->safeEmail(), $shared);
        $this->repo->create($this->faker->safeEmail(), $shared);
        $this->repo->create($this->faker->safeEmail(), $unique);

        $repos = $this->repo->getActiveRepositories();
        sort($repos);
        $expected = [$shared, $unique];
        sort($expected);
        $this->assertSame($expected, $repos);
    }

    public function testGetMetricsReflectsTableState(): void
    {
        $repoWithRelease = $this->repoName();
        $this->repo->create($this->faker->safeEmail(), $repoWithRelease);
        $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $this->repo->updateLastSeenTag($repoWithRelease, 'v1.0.0');

        $metrics = $this->repo->getMetrics();
        $this->assertSame(2, $metrics['subscriptions']);
        $this->assertSame(2, $metrics['repositories']);
        $this->assertSame(1, $metrics['repositories_with_releases']);
    }

    public function testRecordAndQueryNotificationLifecycle(): void
    {
        $sub = $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $tag = 'v' . $this->faker->numerify('#.#.#');

        $this->assertFalse(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], $sub['repository'], $tag)
        );

        $this->repo->recordNotificationResult($sub['id'], $sub['repository'], $tag, true);
        $this->assertTrue(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], $sub['repository'], $tag)
        );
    }

    public function testRecordNotificationResultUpsertsOnRetry(): void
    {
        $sub = $this->repo->create($this->faker->safeEmail(), $this->repoName());
        $tag = 'v' . $this->faker->numerify('#.#.#');

        $this->repo->recordNotificationResult($sub['id'], $sub['repository'], $tag, false, 'smtp down');
        $this->repo->recordNotificationResult($sub['id'], $sub['repository'], $tag, true);

        $this->assertTrue(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], $sub['repository'], $tag)
        );
    }

    public function testGetRepositoriesToScanOrdersByLastChecked(): void
    {
        $checkedEarlier = $this->repoName();
        $neverChecked = $this->repoName();

        $this->repo->create($this->faker->safeEmail(), $checkedEarlier);
        $this->repo->create($this->faker->safeEmail(), $neverChecked);
        $this->repo->updateLastChecked($checkedEarlier);

        $toScan = $this->repo->getRepositoriesToScan(10);
        $this->assertSame([$neverChecked, $checkedEarlier], $toScan);
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
