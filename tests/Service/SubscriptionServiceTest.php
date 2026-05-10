<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;
use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use App\Repository\SubscriptionRepositoryInterface;
use App\Repository\TrackedRepositoryRepositoryInterface;
use App\Service\GitHubServiceInterface;
use App\Service\SubscriptionService;
use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;
use App\Validation\SubscriptionValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubscriptionServiceTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;
    private TrackedRepositoryRepositoryInterface&MockObject $trackedRepositories;
    private GitHubServiceInterface&MockObject $gitHub;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->trackedRepositories = $this->createMock(TrackedRepositoryRepositoryInterface::class);
        $this->gitHub = $this->createMock(GitHubServiceInterface::class);
        $this->service = new SubscriptionService(
            $this->repository,
            $this->trackedRepositories,
            $this->gitHub,
            new SubscriptionValidator(new EmailValidator(), new RepositoryNameValidator()),
            new NullLogger()
        );
    }

    public function testSubscribeSuccess(): void
    {
        $this->gitHub->expects($this->once())
            ->method('repositoryExists')
            ->with('golang/go')
            ->willReturn(true);

        $this->trackedRepositories->expects($this->once())
            ->method('ensureExists')
            ->with('golang/go');

        $expected = new Subscription(1, 'test@example.com', 'golang/go', '2024-01-01T00:00:00Z');

        $this->repository->expects($this->once())
            ->method('create')
            ->with('test@example.com', 'golang/go')
            ->willReturn($expected);

        $result = $this->service->subscribe('test@example.com', 'golang/go');
        $this->assertSame($expected, $result);
    }

    public function testSubscribeInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->subscribe('not-an-email', 'golang/go');
    }

    public function testSubscribeInvalidRepositoryFormat(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->subscribe('test@example.com', 'invalid-repo');
    }

    public function testSubscribeRepositoryNotFound(): void
    {
        $this->gitHub->expects($this->once())
            ->method('repositoryExists')
            ->with('nonexistent/repo')
            ->willReturn(false);

        $this->expectException(RepositoryNotFoundException::class);

        $this->service->subscribe('test@example.com', 'nonexistent/repo');
    }

    public function testUnsubscribeSuccess(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(new Subscription(1, 'test@example.com', 'golang/go', '2024-01-01T00:00:00Z'));

        $this->repository->expects($this->once())
            ->method('delete')
            ->with(1);

        $this->service->unsubscribe(1);
    }

    public function testUnsubscribeNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(SubscriptionNotFoundException::class);

        $this->service->unsubscribe(999);
    }

    public function testGetSubscription(): void
    {
        $expected = new Subscription(1, 'test@example.com', 'golang/go', '2024-01-01T00:00:00Z');

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($expected);

        $result = $this->service->getSubscription(1);
        $this->assertSame($expected, $result);
    }

    public function testGetSubscriptionNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(SubscriptionNotFoundException::class);

        $this->service->getSubscription(999);
    }

    public function testListSubscriptionsByEmail(): void
    {
        $pagination = new Pagination(100, 0);
        $expected = new SubscriptionPage(
            [new Subscription(1, 'test@example.com', 'golang/go', '2024-01-01T00:00:00Z')],
            $pagination,
            1
        );

        $this->repository->expects($this->once())
            ->method('findByEmail')
            ->with('test@example.com', $pagination)
            ->willReturn($expected);

        $result = $this->service->listSubscriptions('test@example.com', $pagination);
        $this->assertSame($expected, $result);
        $this->assertFalse($result->hasNextPage());
    }

    public function testListAllSubscriptions(): void
    {
        $pagination = new Pagination(100, 0);
        $expected = new SubscriptionPage(
            [
                new Subscription(1, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z'),
                new Subscription(2, 'c@d.com', 'php/php-src', '2024-01-01T00:00:00Z'),
            ],
            $pagination,
            2
        );

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with($pagination)
            ->willReturn($expected);

        $result = $this->service->listSubscriptions(null, $pagination);
        $this->assertSame($expected, $result);
    }

    public function testListAllSubscriptionsHasNextPageWhenMoreRowsExist(): void
    {
        $pagination = new Pagination(2, 0);
        $page = new SubscriptionPage(
            [
                new Subscription(1, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z'),
                new Subscription(2, 'c@d.com', 'php/php-src', '2024-01-01T00:00:00Z'),
            ],
            $pagination,
            5
        );

        $this->repository->method('findAll')->willReturn($page);

        $result = $this->service->listSubscriptions(null, $pagination);
        $this->assertTrue($result->hasNextPage());
    }
}
