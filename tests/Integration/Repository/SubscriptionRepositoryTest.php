<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\SubscriptionRepository;
use Tests\Integration\IntegrationTestCase;

final class SubscriptionRepositoryTest extends IntegrationTestCase
{
    private SubscriptionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SubscriptionRepository(self::pdo());
    }

    public function testCreatePersistsRowAndAutoRegistersRepository(): void
    {
        $row = $this->repo->create('user@example.com', 'golang/go');

        $this->assertSame('user@example.com', $row['email']);
        $this->assertSame('golang/go', $row['repository']);
        $this->assertGreaterThan(0, $row['id']);
        $this->assertNotEmpty($row['created_at']);

        $info = $this->repo->getRepositoryInfo('golang/go');
        $this->assertNotNull($info, 'create() should register repository');
        $this->assertSame('golang/go', $info['full_name']);
    }

    public function testCreateIsIdempotentOnDuplicate(): void
    {
        $first = $this->repo->create('user@example.com', 'golang/go');
        $second = $this->repo->create('user@example.com', 'golang/go');

        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $this->repo->findAll());
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findById(999999));
    }

    public function testFindByIdReturnsCreatedRow(): void
    {
        $created = $this->repo->create('user@example.com', 'golang/go');
        $found = $this->repo->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['email'], $found['email']);
        $this->assertSame($created['repository'], $found['repository']);
    }

    public function testFindByEmailFiltersAndPaginates(): void
    {
        $this->repo->create('user@example.com', 'golang/go');
        $this->repo->create('user@example.com', 'docker/compose');
        $this->repo->create('other@example.com', 'kubernetes/kubernetes');

        $userRows = $this->repo->findByEmail('user@example.com');
        $this->assertCount(2, $userRows);
        $repos = array_column($userRows, 'repository');
        $this->assertContains('golang/go', $repos);
        $this->assertContains('docker/compose', $repos);

        $firstPage = $this->repo->findByEmail('user@example.com', 1, 0);
        $secondPage = $this->repo->findByEmail('user@example.com', 1, 1);
        $this->assertCount(1, $firstPage);
        $this->assertCount(1, $secondPage);
        $this->assertNotSame($firstPage[0]['id'], $secondPage[0]['id']);
    }

    public function testFindAllPaginates(): void
    {
        $this->repo->create('a@example.com', 'r/one');
        $this->repo->create('b@example.com', 'r/two');
        $this->repo->create('c@example.com', 'r/three');

        $this->assertCount(2, $this->repo->findAll(2, 0));
        $this->assertCount(1, $this->repo->findAll(2, 2));
    }

    public function testDeleteReturnsTrueWhenRowExisted(): void
    {
        $row = $this->repo->create('user@example.com', 'golang/go');
        $this->assertTrue($this->repo->delete($row['id']));
        $this->assertNull($this->repo->findById($row['id']));
    }

    public function testDeleteReturnsFalseWhenRowMissing(): void
    {
        $this->assertFalse($this->repo->delete(999999));
    }

    public function testGetActiveRepositoriesReturnsDistinctList(): void
    {
        $this->repo->create('a@example.com', 'golang/go');
        $this->repo->create('b@example.com', 'golang/go');
        $this->repo->create('a@example.com', 'docker/compose');

        $repos = $this->repo->getActiveRepositories();
        sort($repos);
        $this->assertSame(['docker/compose', 'golang/go'], $repos);
    }

    public function testGetMetricsReflectsTableState(): void
    {
        $this->repo->create('a@example.com', 'golang/go');
        $this->repo->create('b@example.com', 'docker/compose');
        $this->repo->updateLastSeenTag('golang/go', 'v1.0.0');

        $metrics = $this->repo->getMetrics();
        $this->assertSame(2, $metrics['subscriptions']);
        $this->assertSame(2, $metrics['repositories']);
        $this->assertSame(1, $metrics['repositories_with_releases']);
    }

    public function testRecordAndQueryNotificationLifecycle(): void
    {
        $sub = $this->repo->create('user@example.com', 'golang/go');

        $this->assertFalse(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], 'golang/go', 'v1.0.0')
        );

        $this->repo->recordNotificationResult($sub['id'], 'golang/go', 'v1.0.0', true);
        $this->assertTrue(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], 'golang/go', 'v1.0.0')
        );
    }

    public function testRecordNotificationResultUpsertsOnRetry(): void
    {
        $sub = $this->repo->create('user@example.com', 'golang/go');

        $this->repo->recordNotificationResult($sub['id'], 'golang/go', 'v1.0.0', false, 'smtp down');
        $this->repo->recordNotificationResult($sub['id'], 'golang/go', 'v1.0.0', true);

        $this->assertTrue(
            $this->repo->hasSuccessfulNotificationForRelease($sub['id'], 'golang/go', 'v1.0.0')
        );
    }

    public function testGetRepositoriesToScanOrdersByLastChecked(): void
    {
        $this->repo->create('a@example.com', 'a/repo');
        $this->repo->create('b@example.com', 'b/repo');
        $this->repo->updateLastChecked('a/repo');

        $toScan = $this->repo->getRepositoriesToScan(10);
        $this->assertSame(['b/repo', 'a/repo'], $toScan);
    }
}
