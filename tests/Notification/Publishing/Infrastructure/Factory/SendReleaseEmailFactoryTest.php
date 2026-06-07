<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactory;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

final class SendReleaseEmailFactoryTest extends TestCase
{
    private const UUID_V4_PATTERN
        = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testBuildsAMessageStampedWithTheCurrentSchemaFromKnownInputs(): void
    {
        $factory = new SendReleaseEmailFactory();
        $email = new EmailAddress('user@example.com');
        $repository = new RepositoryName('owner/repo');
        $release = new ReleaseSnapshot(
            new ReleaseTag('v1.2.3'),
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00'
        );

        $message = $factory->fromRecipient(123, $email, $repository, $release);

        $this->assertInstanceOf(SendReleaseEmail::class, $message);
        $this->assertSame(SendReleaseEmail::SCHEMA, $message->schema);
        $this->assertSame(123, $message->subscriptionId);
        $this->assertSame($email, $message->email);
        $this->assertSame($repository, $message->repository);
        $this->assertSame($release, $message->release);
    }

    public function testGeneratesAFreshUuidV4FormattedEventIdPerMessage(): void
    {
        $factory = new SendReleaseEmailFactory();

        $first = $factory->fromRecipient(
            1,
            new EmailAddress('a@example.com'),
            new RepositoryName('a/b'),
            $this->release()
        );
        $second = $factory->fromRecipient(
            2,
            new EmailAddress('b@example.com'),
            new RepositoryName('c/d'),
            $this->release()
        );

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $first->eventId);
        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $second->eventId);
        $this->assertNotSame($first->eventId, $second->eventId);
    }

    public function testCapturesOccurredAtAtConstructionTime(): void
    {
        $factory = new SendReleaseEmailFactory();
        $before = new \DateTimeImmutable();

        $message = $factory->fromRecipient(
            1,
            new EmailAddress('a@example.com'),
            new RepositoryName('a/b'),
            $this->release()
        );

        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $message->occurredAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $message->occurredAt->getTimestamp());
    }

    private function release(): ReleaseSnapshot
    {
        return new ReleaseSnapshot(
            new ReleaseTag('v1.0.0'),
            'name',
            'https://example.com',
            '2026-06-07T11:00:00+00:00'
        );
    }
}
