<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Domain;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

final class SendReleaseEmailTest extends TestCase
{
    public function testConstructsAsAnAnemicValueObjectCarryingExactlyTheSevenWireFields(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-06-07T12:00:00+00:00');
        $release = new ReleaseSnapshot(
            new ReleaseTag('v1.2.3'),
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00'
        );

        $message = new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            '11111111-2222-4333-8444-555555555555',
            $occurredAt,
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            $release
        );

        $this->assertSame('SendReleaseEmail/v1', $message->schema);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $message->eventId);
        $this->assertSame($occurredAt, $message->occurredAt);
        $this->assertSame(123, $message->subscriptionId);
        $this->assertSame('user@example.com', $message->email->value());
        $this->assertSame('owner/repo', $message->repository->value());
        $this->assertSame($release, $message->release);
    }

    public function testSchemaConstantIsTheVersionedWireSchemaIdentifier(): void
    {
        $this->assertSame('SendReleaseEmail/v1', SendReleaseEmail::SCHEMA);
    }

    public function testIsNotADomainEvent(): void
    {
        $message = $this->buildMessage();

        $this->assertNotInstanceOf(\App\Shared\Domain\DomainEvent::class, $message);
    }

    public function testIsNotAnAggregateRoot(): void
    {
        $message = $this->buildMessage();

        $this->assertNotInstanceOf(\App\Shared\Domain\Aggregate\AggregateRoot::class, $message);
    }

    private function buildMessage(): SendReleaseEmail
    {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            '11111111-2222-4333-8444-555555555555',
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release name',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                '2026-06-07T11:00:00+00:00'
            )
        );
    }
}
