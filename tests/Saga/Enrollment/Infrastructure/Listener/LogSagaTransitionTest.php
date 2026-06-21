<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Listener;

use App\Saga\Enrollment\Domain\Event\SagaCompleted;
use App\Saga\Enrollment\Domain\Event\SagaStarted;
use App\Saga\Enrollment\Infrastructure\Listener\LogSagaTransition;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogSagaTransitionTest extends TestCase
{
    private const string UUID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    public function testLogsTheTransitionWithCorrelationContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            'saga transition',
            [
                'event' => 'saga.started',
                'saga_id' => self::UUID,
                'subscription_id' => 123,
            ],
        );

        (new LogSagaTransition($logger))(new SagaStarted(self::UUID, 123, new \DateTimeImmutable()));
    }

    public function testSwallowsLoggerFailuresSoASagaTransitionIsNeverAborted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \RuntimeException('log sink down'));

        $listener = new LogSagaTransition($logger);

        // Must not throw — the dispatcher propagates listener exceptions and the start
        // path dispatches inside the atomic-start transaction.
        $listener(new SagaCompleted(self::UUID, 1, new \DateTimeImmutable()));

        $this->addToAssertionCount(1);
    }
}
