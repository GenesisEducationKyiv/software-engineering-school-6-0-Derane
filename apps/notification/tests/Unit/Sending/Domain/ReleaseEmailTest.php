<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\ReleaseEmail;
use PHPUnit\Framework\TestCase;

final class ReleaseEmailTest extends TestCase
{
    public function testConstructsAndExposesAllProperties(): void
    {
        $email = new ReleaseEmail(
            eventId: '11111111-1111-4111-8111-111111111111',
            subscriptionId: 42,
            recipientEmail: 'subscriber@example.com',
            repository: 'owner/repo',
            tagName: 'v1.2.3',
            releaseName: 'Release name',
            releaseBody: 'Release description text.',
            releaseUrl: 'https://github.com/owner/repo/releases/tag/v1.2.3',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );

        self::assertSame('11111111-1111-4111-8111-111111111111', $email->eventId);
        self::assertSame(42, $email->subscriptionId);
        self::assertSame('subscriber@example.com', $email->recipientEmail);
        self::assertSame('owner/repo', $email->repository);
        self::assertSame('v1.2.3', $email->tagName);
        self::assertSame('Release name', $email->releaseName);
        self::assertSame('Release description text.', $email->releaseBody);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $email->releaseUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $email->publishedAt);
    }
}
