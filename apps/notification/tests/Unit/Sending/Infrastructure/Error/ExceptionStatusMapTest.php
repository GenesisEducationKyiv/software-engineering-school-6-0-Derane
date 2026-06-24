<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Error;

use App\Sending\Application\WelcomeAlreadyFailedException;
use App\Sending\Application\WelcomeInFlightException;
use App\Sending\Domain\WelcomeNotificationKey;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Error\WelcomeRequestValidationException;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

final class ExceptionStatusMapTest extends TestCase
{
    private ExceptionStatusMap $map;

    #[\Override]
    protected function setUp(): void
    {
        $this->map = new ExceptionStatusMap();
    }

    public function testValidationMapsToInvalidArgumentAndBadRequest(): void
    {
        $e = new WelcomeRequestValidationException('bad email');

        self::assertSame(GrpcStatus::INVALID_ARGUMENT, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->map->toHttpStatus($e));
    }

    public function testInFlightMapsToAbortedAndConflict(): void
    {
        $e = WelcomeInFlightException::forKey(new WelcomeNotificationKey(123));

        // Load-bearing: WelcomeInFlightException extends \RuntimeException, so the specific
        // arm must precede the \RuntimeException catch-all — otherwise it maps to
        // UNAVAILABLE/503 and the caller retries benign lock contention.
        self::assertSame(GrpcStatus::ABORTED, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $this->map->toHttpStatus($e));
    }

    public function testGenericRuntimeExceptionMapsToUnavailable(): void
    {
        $e = new \RuntimeException('SMTP down');

        self::assertSame(GrpcStatus::UNAVAILABLE, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $this->map->toHttpStatus($e));
    }

    public function testPdoExceptionMapsToUnavailable(): void
    {
        $e = new \PDOException('connection refused');

        self::assertSame(GrpcStatus::UNAVAILABLE, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $this->map->toHttpStatus($e));
    }

    public function testWelcomeAlreadyFailedIsNotAStatusArmFallsThroughToUnavailable(): void
    {
        // WelcomeAlreadyFailedException is a business FAILED outcome, not a status arm:
        // the adapter returns OUTCOME_FAILED before the map. It extends \RuntimeException,
        // so if it ever reaches the map it lands on the RuntimeException arm — never
        // INVALID_ARGUMENT/ABORTED.
        $e = WelcomeAlreadyFailedException::forKey(new WelcomeNotificationKey(7));

        self::assertSame(GrpcStatus::UNAVAILABLE, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $this->map->toHttpStatus($e));
    }

    public function testGenericThrowableMapsToInternal(): void
    {
        $e = new \LogicException('boom');

        self::assertSame(GrpcStatus::INTERNAL, $this->map->toGrpcStatus($e));
        self::assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $this->map->toHttpStatus($e));
    }

    public function testClientMessageEchoesValidationAndInFlightDetail(): void
    {
        $validation = new WelcomeRequestValidationException('Invalid email format');
        self::assertSame('Invalid email format', $this->map->toClientMessage($validation));

        $inFlight = WelcomeInFlightException::forKey(new WelcomeNotificationKey(123));
        self::assertSame($inFlight->getMessage(), $this->map->toClientMessage($inFlight));
    }

    public function testClientMessageHidesGenericFailureDetail(): void
    {
        self::assertSame('Service unavailable', $this->map->toClientMessage(new \RuntimeException('SMTP creds')));
        self::assertSame('Internal server error', $this->map->toClientMessage(new \LogicException('stack trace')));
    }
}
