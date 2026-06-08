<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Domain;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Shared\Domain\ValueObject\ReleaseTag;
use PHPUnit\Framework\TestCase;

final class ReleaseSnapshotTest extends TestCase
{
    public function testConstructsAndExposesTheNestedReleaseFields(): void
    {
        $tagName = new ReleaseTag('v1.2.3');

        $snapshot = new ReleaseSnapshot(
            $tagName,
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00',
            'Release body text',
        );

        $this->assertSame($tagName, $snapshot->tagName);
        $this->assertSame('Release name', $snapshot->name);
        $this->assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $snapshot->htmlUrl);
        $this->assertSame('2026-06-07T11:00:00+00:00', $snapshot->publishedAt);
        $this->assertSame('Release body text', $snapshot->body);
    }
}
