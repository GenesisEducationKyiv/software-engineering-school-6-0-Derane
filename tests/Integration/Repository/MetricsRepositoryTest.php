<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\MetricsRepositoryInterface;
use App\Repository\ScanProgressWriter;
use App\Repository\SubscriptionRepositoryInterface;
use App\Repository\TrackedRepositoryRegistrar;
use Tests\Integration\IntegrationTestCase;

final class MetricsRepositoryTest extends IntegrationTestCase
{
    private MetricsRepositoryInterface $metrics;
    private SubscriptionRepositoryInterface $subscriptions;
    private TrackedRepositoryRegistrar $registrar;
    private ScanProgressWriter $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = $this->c->get(MetricsRepositoryInterface::class);
        $this->subscriptions = $this->c->get(SubscriptionRepositoryInterface::class);
        $this->registrar = $this->c->get(TrackedRepositoryRegistrar::class);
        $this->progress = $this->c->get(ScanProgressWriter::class);
    }

    public function testSnapshotIsZeroOnEmptyDatabase(): void
    {
        $snapshot = $this->metrics->snapshot();

        $this->assertSame(0, $snapshot->subscriptions);
        $this->assertSame(0, $snapshot->repositories);
        $this->assertSame(0, $snapshot->repositoriesWithReleases);
    }

    public function testSnapshotReflectsTableState(): void
    {
        $repoWithRelease = $this->repoName();
        $repoWithoutRelease = $this->repoName();

        $this->registrar->ensureExists($repoWithRelease);
        $this->registrar->ensureExists($repoWithoutRelease);
        $this->subscriptions->create($this->faker->safeEmail(), $repoWithRelease);
        $this->subscriptions->create($this->faker->safeEmail(), $repoWithoutRelease);
        $this->progress->markReleaseSeen($repoWithRelease, 'v1.0.0');

        $snapshot = $this->metrics->snapshot();

        $this->assertSame(2, $snapshot->subscriptions);
        $this->assertSame(2, $snapshot->repositories);
        $this->assertSame(1, $snapshot->repositoriesWithReleases);
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
