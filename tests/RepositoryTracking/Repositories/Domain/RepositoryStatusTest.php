<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Domain;

use App\RepositoryTracking\Repositories\Domain\RepositoryChecked;
use App\RepositoryTracking\Repositories\Domain\ReleaseSeenAdvanced;
use PHPUnit\Framework\TestCase;

final class RepositoryStatusTest extends TestCase
{
    public function testMarkReleaseSeenRecordsExactlyOneReleaseSeenAdvanced(): void
    {
        $status = RepositoryStatusMother::existing('golang/go');

        $status->markReleaseSeen('v1.2.3');

        $events = $status->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(ReleaseSeenAdvanced::class, $event);
        $this->assertSame('golang/go', $event->repository);
        $this->assertSame('v1.2.3', $event->tag);
        $this->assertSame('repository.release_seen_advanced', $event->eventName());
    }

    public function testMarkReleaseSeenUpdatesLastSeenTag(): void
    {
        $status = RepositoryStatusMother::reconstituted('owner/repo', null, null);

        $status->markReleaseSeen('v2.0.0');

        $this->assertSame('v2.0.0', $status->lastSeenTag());
    }

    public function testMarkCheckedRecordsExactlyOneRepositoryChecked(): void
    {
        $status = RepositoryStatusMother::existing('owner/repo');

        $status->markChecked();

        $events = $status->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(RepositoryChecked::class, $event);
        $this->assertSame('owner/repo', $event->repository);
        $this->assertSame('repository.checked', $event->eventName());
    }

    public function testPullingTwiceReturnsEmptyOnSecondPull(): void
    {
        $status = RepositoryStatusMother::existing();

        $status->markChecked();

        $this->assertCount(1, $status->pullDomainEvents());
        $this->assertCount(0, $status->pullDomainEvents());
    }

    public function testReconstituteRecordsNothing(): void
    {
        $status = RepositoryStatusMother::reconstituted('owner/repo', 'v1.0.0', '2026-01-01T00:00:00Z');

        $this->assertCount(0, $status->pullDomainEvents());
    }

    public function testAccessorsReflectReconstitutedState(): void
    {
        $status = RepositoryStatusMother::reconstituted('owner/repo', 'v1.0.0', '2026-01-01T00:00:00Z');

        $this->assertSame('owner/repo', $status->fullName());
        $this->assertSame('v1.0.0', $status->lastSeenTag());
        $this->assertSame('2026-01-01T00:00:00Z', $status->lastCheckedAt());
    }

    public function testExistingCreatesAggregateWithNullState(): void
    {
        $status = RepositoryStatusMother::existing('owner/repo');

        $this->assertSame('owner/repo', $status->fullName());
        $this->assertNull($status->lastSeenTag());
        $this->assertNull($status->lastCheckedAt());
    }
}
