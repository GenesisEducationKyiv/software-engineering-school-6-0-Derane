<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Acl;

use App\Notification\Publishing\Domain\Recipient;
use App\Notification\Publishing\Infrastructure\Acl\SubscriptionSubscriberProvider;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberCollection;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriberRef;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Anti-Corruption Layer: verifies that the Subscription context's
 * SubscriberRef collection is translated into this context's own Recipient
 * list (with a validated EmailAddress), preserving order, and that the
 * repository is passed through untouched.
 */
final class SubscriptionSubscriberProviderTest extends TestCase
{
    private SubscriberFinder&MockObject $finder;
    private SubscriptionSubscriberProvider $provider;

    protected function setUp(): void
    {
        $this->finder = $this->createMock(SubscriberFinder::class);
        $this->provider = new SubscriptionSubscriberProvider($this->finder);
    }

    public function testTranslatesEachSubscriberRefIntoARecipientPreservingOrder(): void
    {
        $repository = new RepositoryName('owner/repo');

        $this->finder->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with($repository)
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'a@example.com'),
                new SubscriberRef(2, 'b@example.com'),
                new SubscriberRef(3, 'c@example.com'),
            ]));

        $recipients = $this->provider->findRecipientsForRepository($repository);

        self::assertCount(3, $recipients);
        self::assertContainsOnlyInstancesOf(Recipient::class, $recipients);
        self::assertSame([1, 2, 3], array_map(static fn(Recipient $r): int => $r->subscriptionId, $recipients));
        self::assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_map(static fn(Recipient $r): string => $r->email->value(), $recipients)
        );
    }

    public function testReturnsAnEmptyListWhenThereAreNoSubscribers(): void
    {
        $this->finder->expects($this->once())
            ->method('findSubscribersByRepository')
            ->willReturn(new SubscriberCollection([]));

        self::assertSame([], $this->provider->findRecipientsForRepository(new RepositoryName('owner/repo')));
    }
}
