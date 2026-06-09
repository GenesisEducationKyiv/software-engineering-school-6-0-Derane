<?php

declare(strict_types=1);

namespace Tests\Application\Event\Factory;

use App\Application\Event\Factory\ApplicationEventFactory;
use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ReleaseDetected;
use App\Application\Event\ReleaseNotificationFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\SubscriptionCreated;
use App\Application\Event\SubscriptionDeleted;
use PHPUnit\Framework\TestCase;

class ApplicationEventFactoryTest extends TestCase
{
    private ApplicationEventFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ApplicationEventFactory();
    }

    public function testBuildsScanCycleCompletedWithGivenValues(): void
    {
        $event = $this->factory->cycleCompleted(12, 3.5);

        $this->assertInstanceOf(ScanCycleCompleted::class, $event);
        $this->assertSame(12, $event->repositoryCount);
        $this->assertSame(3.5, $event->durationSeconds);
    }

    public function testBuildsReleaseDetectedCarryingPreviousTag(): void
    {
        $event = $this->factory->releaseDetected('golang/go', 'v1.22', 'v1.21');

        $this->assertInstanceOf(ReleaseDetected::class, $event);
        $this->assertSame('golang/go', $event->repository);
        $this->assertSame('v1.22', $event->tag);
        $this->assertSame('v1.21', $event->previousTag);
    }

    public function testBuildsNotificationBatchCompleted(): void
    {
        $event = $this->factory->notificationBatchCompleted('a/b', 'v2', true);

        $this->assertInstanceOf(NotificationBatchCompleted::class, $event);
        $this->assertSame('a/b', $event->repository);
        $this->assertSame('v2', $event->tag);
        $this->assertTrue($event->allDelivered);
    }

    public function testBuildsNotificationFailedSanitizingTheThrowableToScalars(): void
    {
        $event = $this->factory->notificationFailed('u@e.com', 'a/b', new \RuntimeException('smtp down'));

        $this->assertInstanceOf(ReleaseNotificationFailed::class, $event);
        $this->assertSame('u@e.com', $event->email);
        $this->assertSame('a/b', $event->repository);
        $this->assertSame(\RuntimeException::class, $event->errorClass);
        $this->assertSame('smtp down', $event->errorMessage);
    }

    public function testBuildsSubscriptionLifecycleEvents(): void
    {
        $created = $this->factory->subscriptionCreated('u@e.com', 'a/b');
        $deleted = $this->factory->subscriptionDeleted(42);

        $this->assertInstanceOf(SubscriptionCreated::class, $created);
        $this->assertSame('u@e.com', $created->email);
        $this->assertInstanceOf(SubscriptionDeleted::class, $deleted);
        $this->assertSame(42, $deleted->id);
    }
}
