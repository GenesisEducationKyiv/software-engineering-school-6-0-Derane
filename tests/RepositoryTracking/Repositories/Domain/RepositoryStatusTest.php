<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Domain;

use PHPUnit\Framework\TestCase;

final class RepositoryStatusTest extends TestCase
{
    public function testAccessorsReflectReconstitutedState(): void
    {
        $status = RepositoryStatusMother::reconstituted('owner/repo', 'v1.0.0', '2026-01-01T00:00:00Z');

        $this->assertSame('owner/repo', $status->fullName());
        $this->assertSame('v1.0.0', $status->lastSeenTag());
        $this->assertSame('2026-01-01T00:00:00Z', $status->lastCheckedAt());
    }

    public function testExistingCreatesSnapshotWithNullState(): void
    {
        $status = RepositoryStatusMother::existing('owner/repo');

        $this->assertSame('owner/repo', $status->fullName());
        $this->assertNull($status->lastSeenTag());
        $this->assertNull($status->lastCheckedAt());
    }
}
