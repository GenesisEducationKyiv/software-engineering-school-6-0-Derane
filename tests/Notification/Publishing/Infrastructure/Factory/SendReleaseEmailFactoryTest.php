<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\EventIdGenerator;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactory;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

final class SendReleaseEmailFactoryTest extends TestCase
{
    private const FROZEN_NOW = '2026-06-07T12:00:00+00:00';

    /** @param list<string> $eventIds consumed in order, one per fromRecipient() call */
    private function factory(array $eventIds = ['11111111-2222-4333-8444-555555555555']): SendReleaseEmailFactory
    {
        $generator = new class ($eventIds) implements EventIdGenerator {
            /** @param list<string> $queue */
            public function __construct(private array $queue)
            {
            }

            #[\Override]
            public function generate(): string
            {
                $next = array_shift($this->queue);
                \assert($next !== null, 'EventIdGenerator stub exhausted');

                return $next;
            }
        };

        return new SendReleaseEmailFactory(FrozenClock::at(self::FROZEN_NOW), $generator);
    }

    public function testBuildsAMessageStampedWithTheCurrentSchemaFromKnownInputs(): void
    {
        $email = new EmailAddress('user@example.com');
        $repository = new RepositoryName('owner/repo');
        $release = new ReleaseSnapshot(
            new ReleaseTag('v1.2.3'),
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00',
            'release notes body',
        );

        $message = $this->factory()->fromRecipient(123, $email, $repository, $release);

        $this->assertInstanceOf(SendReleaseEmail::class, $message);
        $this->assertSame(SendReleaseEmail::SCHEMA, $message->schema);
        $this->assertSame(123, $message->subscriptionId);
        $this->assertSame($email, $message->email);
        $this->assertSame($repository, $message->repository);
        $this->assertSame($release, $message->release);
    }

    public function testStampsEachMessageWithTheNextGeneratedEventId(): void
    {
        $factory = $this->factory([
            'aaaaaaaa-1111-4111-8111-111111111111',
            'bbbbbbbb-2222-4222-8222-222222222222',
        ]);

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

        $this->assertSame('aaaaaaaa-1111-4111-8111-111111111111', $first->eventId);
        $this->assertSame('bbbbbbbb-2222-4222-8222-222222222222', $second->eventId);
    }

    public function testStampsOccurredAtFromTheClock(): void
    {
        $message = $this->factory()->fromRecipient(
            1,
            new EmailAddress('a@example.com'),
            new RepositoryName('a/b'),
            $this->release()
        );

        $this->assertSame(self::FROZEN_NOW, $message->occurredAt->format(\DateTimeInterface::ATOM));
    }

    private function release(): ReleaseSnapshot
    {
        return new ReleaseSnapshot(
            new ReleaseTag('v1.0.0'),
            'name',
            'https://example.com',
            '2026-06-07T11:00:00+00:00',
            'release notes body',
        );
    }
}
