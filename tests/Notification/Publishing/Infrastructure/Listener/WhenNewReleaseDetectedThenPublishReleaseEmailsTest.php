<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Listener;

use App\Releases\Sourcing\Domain\NewReleaseDetected;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Infrastructure\Listener\WhenNewReleaseDetectedThenPublishReleaseEmails;
use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberCollection;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriberRef;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WhenNewReleaseDetectedThenPublishReleaseEmailsTest extends TestCase
{
    private SubscriberFinder&MockObject $subscribers;
    private SendReleaseEmailFactoryInterface&MockObject $messageFactory;
    private ReleaseNotificationPublisher&MockObject $publisher;
    private WhenNewReleaseDetectedThenPublishReleaseEmails $listener;

    protected function setUp(): void
    {
        $this->subscribers = $this->createMock(SubscriberFinder::class);
        $this->messageFactory = $this->createMock(SendReleaseEmailFactoryInterface::class);
        $this->publisher = $this->createMock(ReleaseNotificationPublisher::class);

        $this->listener = new WhenNewReleaseDetectedThenPublishReleaseEmails(
            $this->subscribers,
            $this->messageFactory,
            $this->publisher
        );
    }

    private function release(string $tag = 'v1.2.3'): Release
    {
        return new Release(
            $tag,
            'Release name',
            'https://github.com/owner/repo/releases/tag/' . $tag,
            '2026-06-07T11:00:00+00:00',
            'release notes body'
        );
    }

    private function event(?Release $release = null): NewReleaseDetected
    {
        return new NewReleaseDetected(
            new RepositoryName('owner/repo'),
            $release ?? $this->release(),
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00')
        );
    }

    private function message(int $subscriptionId): SendReleaseEmail
    {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            sprintf('11111111-2222-4333-8444-%012d', $subscriptionId),
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            $subscriptionId,
            new EmailAddress('user' . $subscriptionId . '@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release name',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                '2026-06-07T11:00:00+00:00',
                'release notes body',
            )
        );
    }

    public function testResolvesExactlyTheRepositorysSubscribersAndPublishesOneDistinctMessagePerRecipient(): void
    {
        $event = $this->event();

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with('owner/repo')
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'a@example.com'),
                new SubscriberRef(2, 'b@example.com'),
                new SubscriberRef(3, 'c@example.com'),
            ]));

        $eventRepository = $event->repository;

        /** @var list<array{0: int, 1: EmailAddress, 2: RepositoryName, 3: ReleaseSnapshot}> $factoryCalls */
        $factoryCalls = [];
        $this->messageFactory->expects($this->exactly(3))
            ->method('fromRecipient')
            ->willReturnCallback(function (
                int $subscriptionId,
                EmailAddress $email,
                RepositoryName $repository,
                ReleaseSnapshot $release
            ) use (
                &$factoryCalls,
                $eventRepository
            ): SendReleaseEmail {
                $factoryCalls[] = [$subscriptionId, $email, $repository, $release];

                self::assertSame($eventRepository, $repository);

                return $this->message($subscriptionId);
            });

        /** @var list<SendReleaseEmail> $published */
        $published = [];
        $this->publisher->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(function (SendReleaseEmail $message) use (&$published): void {
                $published[] = $message;
            });

        ($this->listener)($event);

        self::assertCount(3, $factoryCalls);
        self::assertSame([1, 2, 3], array_column($factoryCalls, 0));
        self::assertSame('a@example.com', $factoryCalls[0][1]->value());
        self::assertSame('b@example.com', $factoryCalls[1][1]->value());
        self::assertSame('c@example.com', $factoryCalls[2][1]->value());

        // ReleaseSnapshot is mapped exactly ONCE — same instance reused for every recipient.
        self::assertSame($factoryCalls[0][3], $factoryCalls[1][3]);
        self::assertSame($factoryCalls[0][3], $factoryCalls[2][3]);
        self::assertSame('v1.2.3', $factoryCalls[0][3]->tagName->value());
        self::assertSame('Release name', $factoryCalls[0][3]->name);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $factoryCalls[0][3]->htmlUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $factoryCalls[0][3]->publishedAt);
        self::assertSame('release notes body', $factoryCalls[0][3]->body);

        self::assertCount(3, $published);
        self::assertSame([1, 2, 3], array_map(static fn(SendReleaseEmail $m): int => $m->subscriptionId, $published));
    }

    public function testPropagatesPublisherFailureUncaughtAndStopsAfterTheFailingCall(): void
    {
        $event = $this->event();

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'a@example.com'),
                new SubscriberRef(2, 'b@example.com'),
                new SubscriberRef(3, 'c@example.com'),
            ]));

        $this->messageFactory->expects($this->exactly(2))
            ->method('fromRecipient')
            ->willReturnCallback(fn(int $subscriptionId): SendReleaseEmail => $this->message($subscriptionId));

        $publishCalls = 0;
        $this->publisher->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function () use (&$publishCalls): void {
                $publishCalls++;
                if ($publishCalls === 2) {
                    throw new \RuntimeException('publish failed (simulated)');
                }
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish failed (simulated)');

        try {
            ($this->listener)($event);
        } finally {
            self::assertSame(2, $publishCalls, 'publish() must not run for the third recipient once the second throws');
        }
    }

    public function testZeroSubscribersPublishesNothingAndReturnsNormally(): void
    {
        $event = $this->event();

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with('owner/repo')
            ->willReturn(new SubscriberCollection([]));

        $this->messageFactory->expects($this->never())->method('fromRecipient');
        $this->publisher->expects($this->never())->method('publish');

        ($this->listener)($event);
    }

    public function testSkipsPublishingWhenTheReleaseHasNoTagMirroringNotificationDispatchersGuard(): void
    {
        $event = $this->event(new Release(null, 'name', 'https://example.test', '2026-01-01T00:00:00+00:00', 'body'));

        $this->subscribers->expects($this->never())->method('findSubscribersByRepository');
        $this->messageFactory->expects($this->never())->method('fromRecipient');
        $this->publisher->expects($this->never())->method('publish');

        ($this->listener)($event);
    }
}
