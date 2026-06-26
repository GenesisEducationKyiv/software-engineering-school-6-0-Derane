<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Application;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\Recipient;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Domain\SubscriberProvider;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PublishReleaseEmailsForReleaseTest extends TestCase
{
    private SubscriberProvider&MockObject $subscribers;
    private SendReleaseEmailFactoryInterface&MockObject $messageFactory;
    private ReleaseNotificationPublisher&MockObject $publisher;
    private PublishReleaseEmailsForRelease $useCase;

    protected function setUp(): void
    {
        $this->subscribers = $this->createMock(SubscriberProvider::class);
        $this->messageFactory = $this->createMock(SendReleaseEmailFactoryInterface::class);
        $this->publisher = $this->createMock(ReleaseNotificationPublisher::class);

        $this->useCase = new PublishReleaseEmailsForRelease(
            $this->subscribers,
            $this->messageFactory,
            $this->publisher
        );
    }

    private function snapshot(): ReleaseSnapshot
    {
        return new ReleaseSnapshot(
            new ReleaseTag('v1.2.3'),
            'Release name',
            'https://github.com/owner/repo/releases/tag/v1.2.3',
            '2026-06-07T11:00:00+00:00',
            'release notes body',
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
            $this->snapshot()
        );
    }

    public function testResolvesTheRepositorysRecipientsAndPublishesOneDistinctMessagePerRecipientAsOneBatch(): void
    {
        $repository = new RepositoryName('owner/repo');
        $snapshot = $this->snapshot();

        $this->subscribers->expects($this->once())
            ->method('findRecipientsForRepository')
            ->with(new RepositoryName('owner/repo'))
            ->willReturn([
                $this->recipient(1, 'a@example.com'),
                $this->recipient(2, 'b@example.com'),
                $this->recipient(3, 'c@example.com'),
            ]);

        /** @var list<array{0: int, 1: EmailAddress, 2: RepositoryName, 3: ReleaseSnapshot}> $factoryCalls */
        $factoryCalls = [];
        $this->messageFactory->expects($this->exactly(3))
            ->method('fromRecipient')
            ->willReturnCallback(function (
                int $subscriptionId,
                EmailAddress $email,
                RepositoryName $messageRepository,
                ReleaseSnapshot $release
            ) use (
                &$factoryCalls,
                $repository,
                $snapshot
            ): SendReleaseEmail {
                $factoryCalls[] = [$subscriptionId, $email, $messageRepository, $release];

                self::assertSame($repository, $messageRepository);
                self::assertSame($snapshot, $release);

                return $this->message($subscriptionId);
            });

        /** @var list<SendReleaseEmail> $published */
        $published = [];
        $this->publisher->expects($this->once())
            ->method('publishAll')
            ->willReturnCallback(function (array $messages) use (&$published): void {
                /** @var list<SendReleaseEmail> $messages */
                $published = $messages;
            });

        ($this->useCase)($repository, $snapshot);

        self::assertCount(3, $factoryCalls);
        self::assertSame([1, 2, 3], array_column($factoryCalls, 0));
        self::assertSame('a@example.com', $factoryCalls[0][1]->value());
        self::assertSame('b@example.com', $factoryCalls[1][1]->value());
        self::assertSame('c@example.com', $factoryCalls[2][1]->value());

        // The whole batch goes to the publisher in one call, recipient order preserved.
        self::assertCount(3, $published);
        self::assertSame([1, 2, 3], array_map(static fn(SendReleaseEmail $m): int => $m->subscriptionId, $published));
    }

    public function testPropagatesPublisherFailureUncaught(): void
    {
        $this->subscribers->expects($this->once())
            ->method('findRecipientsForRepository')
            ->willReturn([
                $this->recipient(1, 'a@example.com'),
                $this->recipient(2, 'b@example.com'),
            ]);

        $this->messageFactory->expects($this->exactly(2))
            ->method('fromRecipient')
            ->willReturnCallback(fn(int $subscriptionId): SendReleaseEmail => $this->message($subscriptionId));

        $this->publisher->expects($this->once())
            ->method('publishAll')
            ->willThrowException(new \RuntimeException('publish failed (simulated)'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish failed (simulated)');

        ($this->useCase)(new RepositoryName('owner/repo'), $this->snapshot());
    }

    public function testZeroRecipientsPublishesNothingAndReturnsNormally(): void
    {
        $this->subscribers->expects($this->once())
            ->method('findRecipientsForRepository')
            ->with(new RepositoryName('owner/repo'))
            ->willReturn([]);

        $this->messageFactory->expects($this->never())->method('fromRecipient');
        $this->publisher->expects($this->never())->method('publishAll');

        ($this->useCase)(new RepositoryName('owner/repo'), $this->snapshot());
    }
}
