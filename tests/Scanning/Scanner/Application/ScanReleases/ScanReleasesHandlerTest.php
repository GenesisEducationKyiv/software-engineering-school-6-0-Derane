<?php

declare(strict_types=1);

namespace Tests\Scanning\Scanner\Application\ScanReleases;

use App\Releases\Sourcing\Domain\RateLimitException;
use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Repository\NotificationLedgerInterface;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesHandler;
use App\Service\NotificationDispatcher;
use App\Service\NotifierInterface;
use App\Service\ReleaseDetector;
use App\Subscription\Subscriptions\Domain\SubscriberCollection;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriberRef;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ScanReleasesHandlerTest extends TestCase
{
    private SubscriberFinder&MockObject $subscribers;
    private ScanCandidateSource&MockObject $candidates;
    private ScanProgressWriter&MockObject $progress;
    private RepositoryStatusReader&MockObject $statusReader;
    private NotificationLedgerInterface&MockObject $ledger;
    private ReleaseSource&MockObject $gitHub;
    private NotifierInterface&MockObject $notifier;
    private ScanReleasesHandler $handler;

    protected function setUp(): void
    {
        $this->subscribers = $this->createMock(SubscriberFinder::class);
        $this->candidates = $this->createMock(ScanCandidateSource::class);
        $this->progress = $this->createMock(ScanProgressWriter::class);
        $this->statusReader = $this->createMock(RepositoryStatusReader::class);
        $this->ledger = $this->createMock(NotificationLedgerInterface::class);
        $this->gitHub = $this->createMock(ReleaseSource::class);
        $this->notifier = $this->createMock(NotifierInterface::class);

        $this->handler = new ScanReleasesHandler(
            $this->candidates,
            $this->progress,
            new ReleaseDetector($this->gitHub, $this->statusReader, new NullLogger()),
            new NotificationDispatcher($this->subscribers, $this->ledger, $this->notifier),
            new NullLogger()
        );
    }

    private function release(string $tag, string $name = 'Release', string $body = 'notes'): Release
    {
        return new Release($tag, $name, "https://github.com/x/y/releases/tag/{$tag}", '2024-01-01', $body);
    }

    public function testHandlerFindsNewRelease(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($this->release('v1.22.0', 'Go 1.22'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->with('golang/go')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.21.0', null));

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with('golang/go')
            ->willReturn(new SubscriberCollection([new SubscriberRef(10, 'user@example.com')]));

        $this->ledger->expects($this->once())
            ->method('hasSuccessfulNotification')
            ->with(10, 'golang/go', 'v1.22.0')
            ->willReturn(false);

        $this->notifier->expects($this->once())
            ->method('notifyReleaseAvailable')
            ->with('user@example.com', 'golang/go', $this->isInstanceOf(Release::class))
            ->willReturn(true);

        $this->ledger->expects($this->once())
            ->method('recordResult')
            ->with(10, 'golang/go', 'v1.22.0', true, null);

        $this->progress->expects($this->once())
            ->method('markReleaseSeen')
            ->with('golang/go', 'v1.22.0');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testHandlerNoNewRelease(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($this->release('v1.21.0'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.21.0', null));

        $this->progress->expects($this->once())
            ->method('markChecked')
            ->with('golang/go');

        $this->notifier->expects($this->never())->method('notifyReleaseAvailable');
        $this->progress->expects($this->never())->method('markReleaseSeen');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testHandlerNoReleases(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['some/repo']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('some/repo')
            ->willReturn(null);

        $this->progress->expects($this->once())
            ->method('markChecked')
            ->with('some/repo');

        $this->notifier->expects($this->never())->method('notifyReleaseAvailable');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testHandlerHandlesRateLimit(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willThrowException(new RateLimitException('60'));

        $this->notifier->expects($this->never())->method('notifyReleaseAvailable');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testHandlerMultipleSubscribers(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn($this->release('v2.0.0', 'Go 2.0', 'Major release'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.0.0', null));

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'a@b.com'),
                new SubscriberRef(2, 'c@d.com'),
                new SubscriberRef(3, 'e@f.com'),
            ]));

        $this->ledger->expects($this->exactly(3))
            ->method('hasSuccessfulNotification')
            ->willReturn(false);

        $this->notifier->expects($this->exactly(3))
            ->method('notifyReleaseAvailable')
            ->willReturn(true);

        $this->ledger->expects($this->exactly(3))
            ->method('recordResult')
            ->withAnyParameters();

        $this->progress->expects($this->once())
            ->method('markReleaseSeen')
            ->with('golang/go', 'v2.0.0');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testHandlerFirstRelease(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['new/repo']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn($this->release('v1.0.0', 'First Release', 'Initial release'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->willReturn(RepositoryStatus::reconstitute('new/repo', null, null));

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->willReturn(new SubscriberCollection([new SubscriberRef(99, 'user@test.com')]));

        $this->ledger->expects($this->once())
            ->method('hasSuccessfulNotification')
            ->with(99, 'new/repo', 'v1.0.0')
            ->willReturn(false);

        $this->notifier->expects($this->once())
            ->method('notifyReleaseAvailable')
            ->willReturn(true);

        $this->ledger->expects($this->once())
            ->method('recordResult')
            ->with(99, 'new/repo', 'v1.0.0', true, null);

        $this->progress->expects($this->once())
            ->method('markReleaseSeen')
            ->with('new/repo', 'v1.0.0');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testDoesNotUpdateLastSeenTagWhenAnyNotificationFails(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn($this->release('v2.0.0', 'Go 2.0', 'Major release'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v1.0.0', null));

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'a@b.com'),
                new SubscriberRef(2, 'c@d.com'),
            ]));

        $this->ledger->expects($this->exactly(2))
            ->method('hasSuccessfulNotification')
            ->willReturn(false);

        $this->notifier->expects($this->exactly(2))
            ->method('notifyReleaseAvailable')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->ledger->expects($this->exactly(2))
            ->method('recordResult')
            ->withAnyParameters();

        $this->progress->expects($this->never())->method('markReleaseSeen');
        $this->progress->expects($this->once())
            ->method('markChecked')
            ->with('golang/go');

        $this->handler->__invoke(new ScanReleasesCommand());
    }

    public function testSkipsAlreadyDeliveredSubscribersAndAdvancesReleaseWhenRemainingDeliveriesSucceed(): void
    {
        $this->candidates->expects($this->once())
            ->method('getDueForScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn($this->release('v3.0.0', 'Go 3.0', 'New release'));

        $this->statusReader->expects($this->once())
            ->method('getStatus')
            ->with('golang/go')
            ->willReturn(RepositoryStatus::reconstitute('golang/go', 'v2.0.0', null));

        $this->subscribers->expects($this->once())
            ->method('findSubscribersByRepository')
            ->with('golang/go')
            ->willReturn(new SubscriberCollection([
                new SubscriberRef(1, 'already@sent.test'),
                new SubscriberRef(2, 'pending@test.com'),
            ]));

        $this->ledger->expects($this->exactly(2))
            ->method('hasSuccessfulNotification')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->notifier->expects($this->once())
            ->method('notifyReleaseAvailable')
            ->with('pending@test.com', 'golang/go', $this->isInstanceOf(Release::class))
            ->willReturn(true);

        $this->ledger->expects($this->once())
            ->method('recordResult')
            ->with(2, 'golang/go', 'v3.0.0', true, null);

        $this->progress->expects($this->once())
            ->method('markReleaseSeen')
            ->with('golang/go', 'v3.0.0');

        $this->handler->__invoke(new ScanReleasesCommand());
    }
}
