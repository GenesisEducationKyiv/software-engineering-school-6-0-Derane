<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\Release;
use App\Domain\SubscriberCollection;
use App\Domain\SubscriberRef;
use App\Repository\NotificationLedgerInterface;
use App\Repository\SubscriberFinderInterface;
use App\Service\NotificationDispatcher;
use App\Service\NotifierInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    private SubscriberFinderInterface&MockObject $subscribers;
    private NotificationLedgerInterface&MockObject $ledger;
    private NotifierInterface&MockObject $notifier;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->subscribers = $this->createMock(SubscriberFinderInterface::class);
        $this->ledger = $this->createMock(NotificationLedgerInterface::class);
        $this->notifier = $this->createMock(NotifierInterface::class);
        $this->dispatcher = new NotificationDispatcher($this->subscribers, $this->ledger, $this->notifier);
    }

    public function testDispatchUsesSubscriberLookupPort(): void
    {
        $release = new Release('v1.0.0', 'Release', 'https://github.com/acme/tool/releases/v1.0.0', '2026-05-10', '');

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with('acme/tool')
            ->willReturn(new SubscriberCollection([new SubscriberRef(7, 'user@example.com')]));

        $this->ledger->expects($this->once())
            ->method('hasSuccessfulNotification')
            ->with(7, 'acme/tool', 'v1.0.0')
            ->willReturn(false);

        $this->notifier->expects($this->once())
            ->method('notifyReleaseAvailable')
            ->with('user@example.com', 'acme/tool', $release)
            ->willReturn(true);

        $this->ledger->expects($this->once())
            ->method('recordResult')
            ->with(7, 'acme/tool', 'v1.0.0', true, null);

        self::assertTrue($this->dispatcher->dispatch('acme/tool', $release));
    }
}
