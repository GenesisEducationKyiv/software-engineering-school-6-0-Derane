<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Application\Start;

use App\Saga\Enrollment\Application\Start\StartEnrollmentSagaService;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StartEnrollmentSagaServiceTest extends TestCase
{
    public function testDelegatesStartToThePersistenceStarterWithThePrimitiveId(): void
    {
        /** @var EnrollmentSagaStarter&MockObject $persistence */
        $persistence = $this->createMock(EnrollmentSagaStarter::class);
        $persistence->expects($this->once())->method('start')->with(123);

        $service = new StartEnrollmentSagaService($persistence);

        $service->start(123);
    }

    public function testIsAnEnrollmentSagaStarter(): void
    {
        $service = new StartEnrollmentSagaService($this->createMock(EnrollmentSagaStarter::class));

        $this->assertInstanceOf(EnrollmentSagaStarter::class, $service);
    }
}
