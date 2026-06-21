<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Error;

use App\Saga\Enrollment\Domain\SagaNotFoundException;
use App\Shared\Infrastructure\Error\ExceptionStatusMap;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

final class ExceptionStatusMapTest extends TestCase
{
    private ExceptionStatusMap $map;

    protected function setUp(): void
    {
        $this->map = new ExceptionStatusMap();
    }

    public function testSagaNotFoundMapsToHttpNotFound(): void
    {
        $e = SagaNotFoundException::forSubscriptionId(123);

        $this->assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $this->map->toHttpStatus($e));
    }

    public function testSagaNotFoundMapsToGrpcNotFound(): void
    {
        $e = SagaNotFoundException::withSagaId('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33');

        $this->assertSame(GrpcStatus::NOT_FOUND, $this->map->toGrpcStatus($e));
    }

    public function testSagaNotFoundSurfacesItsOwnMessage(): void
    {
        $e = SagaNotFoundException::forSubscriptionId(7);

        $this->assertSame('Enrollment saga for subscription #7 not found', $this->map->toClientMessage($e));
    }

    public function testUnmappedThrowableStillFallsBackToInternal(): void
    {
        $e = new \RuntimeException('boom');

        $this->assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $this->map->toHttpStatus($e));
        $this->assertSame(GrpcStatus::INTERNAL, $this->map->toGrpcStatus($e));
        $this->assertSame('Internal server error', $this->map->toClientMessage($e));
    }
}
