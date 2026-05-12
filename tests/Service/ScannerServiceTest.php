<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Exception\RateLimitException;
use App\Repository\SubscriptionRepositoryInterface;
use App\Service\GitHubServiceInterface;
use App\Service\NotifierInterface;
use App\Service\ScannerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScannerServiceTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;
    private GitHubServiceInterface&MockObject $gitHub;
    private NotifierInterface&MockObject $notifier;
    private ScannerService $scanner;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->gitHub = $this->createMock(GitHubServiceInterface::class);
        $this->notifier = $this->createMock(NotifierInterface::class);
        $this->scanner = new ScannerService(
            $this->repository,
            $this->gitHub,
            $this->notifier,
            new NullLogger()
        );
    }

    public function testScanFindsNewRelease(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn([
                'tag_name' => 'v1.22.0',
                'name' => 'Go 1.22',
                'html_url' => 'https://github.com/golang/go/releases/tag/v1.22.0',
                'published_at' => '2024-01-01',
                'body' => 'Release notes',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->with('golang/go')
            ->willReturn(['last_seen_tag' => 'v1.21.0']);

        $this->repository->expects($this->once())
            ->method('getSubscriptionsByRepository')
            ->with('golang/go')
            ->willReturn([['id' => 10, 'email' => 'user@example.com']]);

        $this->repository->expects($this->once())
            ->method('hasSuccessfulNotificationForRelease')
            ->with(10, 'golang/go', 'v1.22.0')
            ->willReturn(false);

        $this->notifier->expects($this->once())
            ->method('sendReleaseNotification')
            ->with('user@example.com', 'golang/go', 'v1.22.0', 'Go 1.22', $this->anything(), $this->anything())
            ->willReturn(true);

        $this->repository->expects($this->once())
            ->method('recordNotificationResult')
            ->with(10, 'golang/go', 'v1.22.0', true, null);

        $this->repository->expects($this->once())
            ->method('updateLastSeenTag')
            ->with('golang/go', 'v1.22.0');

        $this->scanner->scan();
    }

    public function testScanNoNewRelease(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn([
                'tag_name' => 'v1.21.0',
                'name' => 'Go 1.21',
                'html_url' => 'https://github.com/golang/go/releases/tag/v1.21.0',
                'published_at' => '2024-01-01',
                'body' => 'Release notes',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->with('golang/go')
            ->willReturn(['last_seen_tag' => 'v1.21.0']);

        $this->notifier->expects($this->never())
            ->method('sendReleaseNotification');

        $this->repository->expects($this->never())
            ->method('updateLastSeenTag');

        $this->scanner->scan();
    }

    public function testScanNoReleases(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['some/repo']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('some/repo')
            ->willReturn(null);

        $this->notifier->expects($this->never())
            ->method('sendReleaseNotification');

        $this->scanner->scan();
    }

    public function testScanHandlesRateLimit(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willThrowException(new RateLimitException('60'));

        $this->notifier->expects($this->never())
            ->method('sendReleaseNotification');

        // Should not throw
        $this->scanner->scan();
    }

    public function testScanMultipleSubscribers(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag_name' => 'v2.0.0',
                'name' => 'Go 2.0',
                'html_url' => 'https://github.com/golang/go/releases/tag/v2.0.0',
                'published_at' => '2024-06-01',
                'body' => 'Major release',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->willReturn(['last_seen_tag' => 'v1.0.0']);

        $this->repository->expects($this->once())
            ->method('getSubscriptionsByRepository')
            ->willReturn([
                ['id' => 1, 'email' => 'a@b.com'],
                ['id' => 2, 'email' => 'c@d.com'],
                ['id' => 3, 'email' => 'e@f.com'],
            ]);

        $this->repository->expects($this->exactly(3))
            ->method('hasSuccessfulNotificationForRelease')
            ->willReturn(false);

        $this->notifier->expects($this->exactly(3))
            ->method('sendReleaseNotification')
            ->willReturn(true);

        $this->repository->expects($this->exactly(3))
            ->method('recordNotificationResult')
            ->withAnyParameters();

        $this->repository->expects($this->once())
            ->method('updateLastSeenTag')
            ->with('golang/go', 'v2.0.0');

        $this->scanner->scan();
    }

    public function testScanFirstRelease(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['new/repo']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag_name' => 'v1.0.0',
                'name' => 'First Release',
                'html_url' => 'https://github.com/new/repo/releases/tag/v1.0.0',
                'published_at' => '2024-01-01',
                'body' => 'Initial release',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->willReturn(['last_seen_tag' => null]);

        $this->repository->expects($this->once())
            ->method('getSubscriptionsByRepository')
            ->willReturn([['id' => 99, 'email' => 'user@test.com']]);

        $this->repository->expects($this->once())
            ->method('hasSuccessfulNotificationForRelease')
            ->with(99, 'new/repo', 'v1.0.0')
            ->willReturn(false);

        $this->notifier->expects($this->once())
            ->method('sendReleaseNotification')
            ->willReturn(true);

        $this->repository->expects($this->once())
            ->method('recordNotificationResult')
            ->with(99, 'new/repo', 'v1.0.0', true, null);

        $this->repository->expects($this->once())
            ->method('updateLastSeenTag')
            ->with('new/repo', 'v1.0.0');

        $this->scanner->scan();
    }

    public function testDoesNotUpdateLastSeenTagWhenAnyNotificationFails(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag_name' => 'v2.0.0',
                'name' => 'Go 2.0',
                'html_url' => 'https://github.com/golang/go/releases/tag/v2.0.0',
                'published_at' => '2024-06-01',
                'body' => 'Major release',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->willReturn(['last_seen_tag' => 'v1.0.0']);

        $this->repository->expects($this->once())
            ->method('getSubscriptionsByRepository')
            ->willReturn([
                ['id' => 1, 'email' => 'a@b.com'],
                ['id' => 2, 'email' => 'c@d.com'],
            ]);

        $this->repository->expects($this->exactly(2))
            ->method('hasSuccessfulNotificationForRelease')
            ->willReturn(false);

        $this->notifier->expects($this->exactly(2))
            ->method('sendReleaseNotification')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->repository->expects($this->exactly(2))
            ->method('recordNotificationResult')
            ->withAnyParameters();

        $this->repository->expects($this->never())
            ->method('updateLastSeenTag');

        $this->repository->expects($this->once())
            ->method('updateLastChecked')
            ->with('golang/go');

        $this->scanner->scan();
    }

    public function testSkipsAlreadyDeliveredSubscribersAndAdvancesReleaseWhenRemainingDeliveriesSucceed(): void
    {
        $this->repository->expects($this->once())
            ->method('getRepositoriesToScan')
            ->with(100)
            ->willReturn(['golang/go']);

        $this->gitHub->expects($this->once())
            ->method('getLatestRelease')
            ->with('golang/go')
            ->willReturn([
                'tag_name' => 'v3.0.0',
                'name' => 'Go 3.0',
                'html_url' => 'https://github.com/golang/go/releases/tag/v3.0.0',
                'published_at' => '2024-08-01',
                'body' => 'New release',
            ]);

        $this->repository->expects($this->once())
            ->method('getRepositoryInfo')
            ->with('golang/go')
            ->willReturn(['last_seen_tag' => 'v2.0.0']);

        $this->repository->expects($this->once())
            ->method('getSubscriptionsByRepository')
            ->with('golang/go')
            ->willReturn([
                ['id' => 1, 'email' => 'already@sent.test'],
                ['id' => 2, 'email' => 'pending@test.com'],
            ]);

        $this->repository->expects($this->exactly(2))
            ->method('hasSuccessfulNotificationForRelease')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->notifier->expects($this->once())
            ->method('sendReleaseNotification')
            ->with('pending@test.com', 'golang/go', 'v3.0.0', 'Go 3.0', $this->anything(), $this->anything())
            ->willReturn(true);

        $this->repository->expects($this->once())
            ->method('recordNotificationResult')
            ->with(2, 'golang/go', 'v3.0.0', true, null);

        $this->repository->expects($this->once())
            ->method('updateLastSeenTag')
            ->with('golang/go', 'v3.0.0');

        $this->scanner->scan();
    }
}
