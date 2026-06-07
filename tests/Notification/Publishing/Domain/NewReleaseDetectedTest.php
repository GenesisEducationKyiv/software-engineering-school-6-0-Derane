<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Domain;

use App\Notification\Publishing\Domain\NewReleaseDetected;
use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

final class NewReleaseDetectedTest extends TestCase
{
    public function testConstructsAsADomainEventCarryingExactlyTheThreeDocumentedFields(): void
    {
        $repository = new RepositoryName('owner/repo');
        $release = new Release(
            'v1.2.3',
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00',
            'notes'
        );
        $occurredOn = new \DateTimeImmutable('2026-06-07T12:00:00+00:00');

        $event = new NewReleaseDetected($repository, $release, $occurredOn);

        $this->assertSame($repository, $event->repository);
        $this->assertSame($release, $event->release);
        $this->assertSame($occurredOn, $event->occurredOn());
    }

    public function testIsADomainEvent(): void
    {
        $event = $this->buildEvent();

        $this->assertInstanceOf(DomainEvent::class, $event);
    }

    public function testEventNameFollowsTheContextDotEventConvention(): void
    {
        $event = $this->buildEvent();

        $this->assertSame('release.new_release_detected', $event->eventName());
    }

    public function testOccurredOnReturnsTheConstructorInjectedTimestampVerbatim(): void
    {
        $occurredOn = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        $event = new NewReleaseDetected(
            new RepositoryName('owner/repo'),
            new Release('v1.0.0', 'name', 'https://example.test', '2026-01-01T00:00:00+00:00', 'body'),
            $occurredOn
        );

        $this->assertSame($occurredOn, $event->occurredOn());
    }

    private function buildEvent(): NewReleaseDetected
    {
        return new NewReleaseDetected(
            new RepositoryName('owner/repo'),
            new Release(
                'v1.2.3',
                'Release name',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                '2026-06-07T11:00:00+00:00',
                'notes'
            ),
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00')
        );
    }
}
