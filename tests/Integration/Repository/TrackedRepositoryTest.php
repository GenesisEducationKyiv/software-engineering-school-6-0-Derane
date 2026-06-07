<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use Tests\Integration\IntegrationTestCase;

final class TrackedRepositoryTest extends IntegrationTestCase
{
    private TrackedRepositoryRegistrar $registrar;
    private ScanProgressWriter $progress;
    private RepositoryStatusReader $statusReader;
    private ScanCandidateSource $candidates;
    private RepositoryCountPort $counts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = $this->c->get(TrackedRepositoryRegistrar::class);
        $this->progress = $this->c->get(ScanProgressWriter::class);
        $this->statusReader = $this->c->get(RepositoryStatusReader::class);
        $this->candidates = $this->c->get(ScanCandidateSource::class);
        $this->counts = $this->c->get(RepositoryCountPort::class);
    }

    public function testEnsureExistsIsIdempotent(): void
    {
        $repo = $this->repoName();

        $this->registrar->ensureExists($repo);
        $this->registrar->ensureExists($repo);

        $status = $this->statusReader->getStatus($repo);
        $this->assertNotNull($status);
        $this->assertSame($repo, $status->fullName());
        $this->assertNull($status->lastSeenTag());
        $this->assertNull($status->lastCheckedAt());
    }

    public function testGetStatusReturnsNullForUnknownRepository(): void
    {
        $this->assertNull($this->statusReader->getStatus($this->repoName()));
    }

    public function testMarkReleaseSeenUpdatesTagAndCheckedTimestamp(): void
    {
        $repo = $this->repoName();
        $this->registrar->ensureExists($repo);

        $this->progress->markReleaseSeen($repo, 'v1.2.3');

        $status = $this->statusReader->getStatus($repo);
        $this->assertNotNull($status);
        $this->assertSame('v1.2.3', $status->lastSeenTag());
        $this->assertNotNull($status->lastCheckedAt());
    }

    public function testMarkCheckedUpdatesTimestampWithoutTouchingTag(): void
    {
        $repo = $this->repoName();
        $this->registrar->ensureExists($repo);
        $this->progress->markReleaseSeen($repo, 'v0.1.0');

        $this->progress->markChecked($repo);

        $status = $this->statusReader->getStatus($repo);
        $this->assertNotNull($status);
        $this->assertSame('v0.1.0', $status->lastSeenTag());
        $this->assertNotNull($status->lastCheckedAt());
    }

    public function testGetDueForScanOrdersByLastCheckedAtNullsFirst(): void
    {
        $checkedEarlier = $this->repoName();
        $neverChecked = $this->repoName();

        $this->registrar->ensureExists($checkedEarlier);
        $this->registrar->ensureExists($neverChecked);
        $this->progress->markChecked($checkedEarlier);

        $this->assertSame(
            [$neverChecked, $checkedEarlier],
            $this->candidates->getDueForScan(10)
        );
    }

    public function testGetDueForScanRespectsLimit(): void
    {
        $this->registrar->ensureExists($this->repoName());
        $this->registrar->ensureExists($this->repoName());
        $this->registrar->ensureExists($this->repoName());

        $this->assertCount(2, $this->candidates->getDueForScan(2));
    }

    public function testCountAllAndCountWithReleasesReflectTableState(): void
    {
        $beforeAll = $this->counts->countAll();
        $beforeWithReleases = $this->counts->countWithReleases();

        $repoWithRelease = $this->repoName();
        $repoWithoutRelease = $this->repoName();
        $this->registrar->ensureExists($repoWithRelease);
        $this->registrar->ensureExists($repoWithoutRelease);
        $this->progress->markReleaseSeen($repoWithRelease, 'v1.0.0');

        $this->assertSame($beforeAll + 2, $this->counts->countAll());
        $this->assertSame($beforeWithReleases + 1, $this->counts->countWithReleases());
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
