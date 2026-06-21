<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Application\Start;

use App\Saga\Enrollment\Application\Start\StartEnrollmentSagaService;
use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Saga\Enrollment\Domain\EnrollmentSagaStore;
use App\Saga\Enrollment\Domain\Event\SagaStarted;
use App\Saga\Enrollment\Domain\SagaState;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class StartEnrollmentSagaServiceTest extends TestCase
{
    private EnrollmentSagaStore&MockObject $store;
    private EventDispatcherInterface&MockObject $dispatcher;
    private StartEnrollmentSagaService $service;

    protected function setUp(): void
    {
        $this->store = $this->createMock(EnrollmentSagaStore::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->service = new StartEnrollmentSagaService($this->store, $this->dispatcher);
    }

    public function testBuildsAStartedSagaForTheIdPersistsItAndDispatchesSagaStarted(): void
    {
        $added = null;
        $this->store->expects($this->once())->method('add')
            ->willReturnCallback(static function (EnrollmentSaga $saga) use (&$added): bool {
                $added = $saga;

                return true;
            });

        $dispatched = [];
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $this->service->start(123);

        $this->assertInstanceOf(EnrollmentSaga::class, $added);
        $this->assertSame(123, $added->subscriptionId());
        $this->assertSame(SagaState::Started, $added->state());
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(SagaStarted::class, $dispatched[0]);
        $this->assertSame(123, $dispatched[0]->subscriptionId);
    }

    public function testDuplicateStartPersistsNoSecondSagaAndDispatchesNothing(): void
    {
        // ON CONFLICT no-op: add() returns false, so no SagaStarted is dispatched.
        $this->store->expects($this->once())->method('add')->willReturn(false);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->service->start(123);
    }

    public function testIsAnEnrollmentSagaStarter(): void
    {
        $this->assertInstanceOf(EnrollmentSagaStarter::class, $this->service);
    }
}
