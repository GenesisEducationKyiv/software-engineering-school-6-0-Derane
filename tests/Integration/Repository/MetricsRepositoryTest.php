<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\MetricsRepositoryInterface;
use App\Repository\ScanProgressWriter;
use App\Repository\TrackedRepositoryRegistrar;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Tests\Integration\IntegrationTestCase;

final class MetricsRepositoryTest extends IntegrationTestCase
{
    private MetricsRepositoryInterface $metrics;
    private SubscriptionRepository $subscriptions;
    private TrackedRepositoryRegistrar $registrar;
    private ScanProgressWriter $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = $this->c->get(MetricsRepositoryInterface::class);
        $this->subscriptions = $this->c->get(SubscriptionRepository::class);
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
        $this->subscribe($this->faker->safeEmail(), $repoWithRelease);
        $this->subscribe($this->faker->safeEmail(), $repoWithoutRelease);
        $this->progress->markReleaseSeen($repoWithRelease, 'v1.0.0');

        $snapshot = $this->metrics->snapshot();

        $this->assertSame(2, $snapshot->subscriptions);
        $this->assertSame(2, $snapshot->repositories);
        $this->assertSame(1, $snapshot->repositoriesWithReleases);
    }

    private function subscribe(string $email, string $repository): void
    {
        $this->subscriptions->create(Subscription::subscribe(
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
