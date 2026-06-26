<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\Recipient;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Domain\SubscriberProvider;
use App\Notification\Publishing\Infrastructure\Listener\PublishReleaseEmailsOnNewReleaseDetectedListener;
use App\Releases\Sourcing\Domain\DetectedRelease;
use App\Releases\Sourcing\Domain\NewReleaseDetected;
use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener is a thin adapter over PublishReleaseEmailsForRelease (final,
 * not mockable), so these tests compose the real use-case with mocked ports
 * and assert the adapter's own responsibility: the once-per-dispatch
 * DetectedRelease → ReleaseSnapshot mapping.
 */
final class PublishReleaseEmailsOnNewReleaseDetectedListenerTest extends TestCase
{
    private SubscriberProvider&MockObject $subscribers;
    private SendReleaseEmailFactoryInterface&MockObject $messageFactory;
    private ReleaseNotificationPublisher&MockObject $publisher;
    private PublishReleaseEmailsOnNewReleaseDetectedListener $listener;

    protected function setUp(): void
    {
        $this->subscribers = $this->createMock(SubscriberProvider::class);
        $this->messageFactory = $this->createMock(SendReleaseEmailFactoryInterface::class);
        $this->publisher = $this->createMock(ReleaseNotificationPublisher::class);

        $this->listener = new PublishReleaseEmailsOnNewReleaseDetectedListener(
            new PublishReleaseEmailsForRelease(
                $this->subscribers,
                $this->messageFactory,
                $this->publisher
            )
        );
    }

    private function detected(string $tag = 'v1.2.3'): DetectedRelease
    {
        return new DetectedRelease(
            new ReleaseTag($tag),
            new Release(
                $tag,
                'Release name',
                'https://github.com/owner/repo/releases/tag/' . $tag,
                '2026-06-07T11:00:00+00:00',
                'release notes body'
            )
        );
    }

    private function event(): NewReleaseDetected
    {
        return new NewReleaseDetected(
            new RepositoryName('owner/repo'),
            $this->detected(),
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00')
        );
    }

    private function recipient(int $subscriptionId, string $email): Recipient
    {
        return new Recipient($subscriptionId, new EmailAddress($email));
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

    public function testMapsTheReleaseIntoOneSharedSnapshotAndDelegatesWithTheEventRepository(): void
    {
        $event = $this->event();

        $this->subscribers->expects($this->once())
            ->method('findRecipientsForRepository')
            ->with(new RepositoryName('owner/repo'))
            ->willReturn([
                $this->recipient(1, 'a@example.com'),
                $this->recipient(2, 'b@example.com'),
                $this->recipient(3, 'c@example.com'),
            ]);

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

        $this->publisher->expects($this->once())
            ->method('publishAll')
            ->with(self::countOf(3));

        ($this->listener)($event);

        self::assertCount(3, $factoryCalls);
        self::assertSame([1, 2, 3], array_column($factoryCalls, 0));

        // ReleaseSnapshot is mapped exactly ONCE — same instance reused for every recipient.
        self::assertSame($factoryCalls[0][3], $factoryCalls[1][3]);
        self::assertSame($factoryCalls[0][3], $factoryCalls[2][3]);
        self::assertSame('v1.2.3', $factoryCalls[0][3]->tagName->value());
        self::assertSame('Release name', $factoryCalls[0][3]->name);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $factoryCalls[0][3]->htmlUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $factoryCalls[0][3]->publishedAt);
        self::assertSame('release notes body', $factoryCalls[0][3]->body);
    }

    public function testPropagatesPublisherFailureUncaughtSoTheScanMarkerIsNotAdvanced(): void
    {
        $this->subscribers->expects($this->once())
            ->method('findRecipientsForRepository')
            ->willReturn([$this->recipient(1, 'a@example.com')]);

        $this->messageFactory->method('fromRecipient')
            ->willReturnCallback(fn(int $subscriptionId): SendReleaseEmail => $this->message($subscriptionId));

        $this->publisher->expects($this->once())
            ->method('publishAll')
            ->willThrowException(new \RuntimeException('publish failed (simulated)'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish failed (simulated)');

        ($this->listener)($this->event());
    }
}
