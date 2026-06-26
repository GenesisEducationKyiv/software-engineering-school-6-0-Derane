<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\NotificationKey;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\ReleaseTag;
use App\Sending\Domain\RepositoryName;
use PHPUnit\Framework\TestCase;

final class ReleaseEmailTest extends TestCase
{
    private function email(): ReleaseEmail
    {
        return new ReleaseEmail(
            eventId: '11111111-1111-4111-8111-111111111111',
            subscriptionId: 42,
            recipientEmail: new EmailAddress('subscriber@example.com'),
            repository: new RepositoryName('owner/repo'),
            tagName: new ReleaseTag('v1.2.3'),
            releaseName: 'Release name',
            releaseBody: 'Release description text.',
            releaseUrl: 'https://github.com/owner/repo/releases/tag/v1.2.3',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );
    }

    public function testConstructsAndExposesAllProperties(): void
    {
        $email = $this->email();

        self::assertSame('11111111-1111-4111-8111-111111111111', $email->eventId);
        self::assertSame(42, $email->subscriptionId);
        self::assertSame('subscriber@example.com', $email->recipientEmail->value());
        self::assertSame('owner/repo', $email->repository->value());
        self::assertSame('v1.2.3', $email->tagName->value());
        self::assertSame('Release name', $email->releaseName);
        self::assertSame('Release description text.', $email->releaseBody);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $email->releaseUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $email->publishedAt);
    }

    public function testKeyExposesTheBusinessIdentityTripleWithoutTheEventId(): void
    {
        self::assertEquals(
            new NotificationKey(42, new ReleaseTag('v1.2.3'), new RepositoryName('owner/repo')),
            $this->email()->key(),
        );
    }
}
