<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use App\Repository\SubscriptionRepositoryInterface;
use App\Service\GitHubServiceInterface;
use App\Service\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubscriptionServiceTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;
    private GitHubServiceInterface&MockObject $gitHub;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->gitHub = $this->createMock(GitHubServiceInterface::class);
        $this->service = new SubscriptionService($this->repository, $this->gitHub, new NullLogger());
    }

    public function testSubscribeSuccess(): void
    {
        $this->gitHub->expects($this->once())
            ->method('repositoryExists')
            ->with('golang/go')
            ->willReturn(true);

        $expected = [
            'id' => 1,
            'email' => 'test@example.com',
            'repository' => 'golang/go',
            'created_at' => '2024-01-01T00:00:00Z',
        ];

        $this->repository->expects($this->once())
            ->method('create')
            ->with('test@example.com', 'golang/go')
            ->willReturn($expected);

        $result = $this->service->subscribe('test@example.com', 'golang/go');
        $this->assertEquals($expected, $result);
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
            ->willReturn(['id' => 1, 'email' => 'test@example.com', 'repository' => 'golang/go']);

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
        $expected = ['id' => 1, 'email' => 'test@example.com', 'repository' => 'golang/go'];

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($expected);

        $result = $this->service->getSubscription(1);
        $this->assertEquals($expected, $result);
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
        $expected = [
            ['id' => 1, 'email' => 'test@example.com', 'repository' => 'golang/go'],
        ];

        $this->repository->expects($this->once())
            ->method('findByEmail')
            ->with('test@example.com', 100, 0)
            ->willReturn($expected);

        $result = $this->service->listSubscriptions('test@example.com');
        $this->assertEquals($expected, $result);
    }

    public function testListAllSubscriptions(): void
    {
        $expected = [
            ['id' => 1, 'email' => 'a@b.com', 'repository' => 'golang/go'],
            ['id' => 2, 'email' => 'c@d.com', 'repository' => 'php/php-src'],
        ];

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(100, 0)
            ->willReturn($expected);

        $result = $this->service->listSubscriptions();
        $this->assertEquals($expected, $result);
    }

    public function testIsValidRepositoryFormat(): void
    {
        $this->assertTrue(SubscriptionService::isValidRepositoryFormat('golang/go'));
        $this->assertTrue(SubscriptionService::isValidRepositoryFormat('php/php-src'));
        $this->assertTrue(SubscriptionService::isValidRepositoryFormat('a/b'));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat('invalid'));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat(''));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat('a/b/c'));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat('/'));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat('a/'));
        $this->assertFalse(SubscriptionService::isValidRepositoryFormat('/b'));
    }

    public function testIsValidEmail(): void
    {
        $this->assertTrue(SubscriptionService::isValidEmail('test@example.com'));
        $this->assertTrue(SubscriptionService::isValidEmail('a.b@c.d.com'));
        $this->assertFalse(SubscriptionService::isValidEmail('not-email'));
        $this->assertFalse(SubscriptionService::isValidEmail(''));
        $this->assertFalse(SubscriptionService::isValidEmail('@'));
    }
}
