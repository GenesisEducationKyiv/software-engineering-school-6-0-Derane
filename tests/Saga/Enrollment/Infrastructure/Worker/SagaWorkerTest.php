<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Worker;

use App\Saga\Enrollment\Application\Relay\RelayPendingWelcomeEmails;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Application\Sweep\SweepTimedOutSagas;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeConsumer;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeMessageMapper;
use App\Saga\Enrollment\Infrastructure\Worker\SagaWorker;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TransactionManager;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The per-tick orchestration is unit-tested in isolation from the supervised
 * run() loop (which needs PCNTL + a live broker, deferred to E4): the relay runs
 * every tick; the sweep runs only every Nth tick. The relay/sweep use-cases are
 * `final readonly` (no interface), so they are constructed REAL with mocked Domain
 * ports — the cadence is observed at those ports (dueForRelay every tick;
 * dueForSweep/dueForStartSweep only on sweep ticks).
 */
final class SagaWorkerTest extends TestCase
{
    private const BATCH = 25;
    private const T = 900;
    private const T_START = 900;
    private const WAIT = 1;
    private const SWEEP_EVERY = 3;

    private EnrollmentSagaReader&MockObject $reader;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(EnrollmentSagaReader::class);
        // No due work: every reader query returns an empty batch.
        $this->reader->method('dueForRelay')->willReturn([]);
        $this->reader->method('dueForSweep')->willReturn([]);
        $this->reader->method('dueForStartSweep')->willReturn([]);
    }

    public function testEveryTickRunsTheRelayAndNoSweepOffCadence(): void
    {
        // Tick 1 is not a sweep tick: the relay queries dueForRelay; the sweep does not.
        $this->reader->expects(self::once())->method('dueForRelay')->with(self::BATCH);
        $this->reader->expects(self::never())->method('dueForSweep');
        $this->reader->expects(self::never())->method('dueForStartSweep');

        $this->worker()->tick(1);
    }

    public function testSweepRunsOnlyOnEveryNthTick(): void
    {
        // Across ticks 1..6 the relay runs 6x; the sweep (both reader queries) fires
        // exactly on ticks 3 and 6.
        $this->reader->expects(self::exactly(6))->method('dueForRelay');
        $this->reader->expects(self::exactly(2))->method('dueForSweep')->with(self::anything(), self::T);
        $this->reader->expects(self::exactly(2))->method('dueForStartSweep')->with(self::anything(), self::T_START);

        $worker = $this->worker();
        for ($tick = 1; $tick <= 6; $tick++) {
            $worker->tick($tick);
        }
    }

    public function testSweepNeverRunsWhenTheCadenceIsNonPositive(): void
    {
        $this->reader->expects(self::exactly(5))->method('dueForRelay');
        $this->reader->expects(self::never())->method('dueForSweep');

        $worker = $this->worker(sweepEvery: 0);
        for ($tick = 1; $tick <= 5; $tick++) {
            $worker->tick($tick);
        }
    }

    private function worker(int $sweepEvery = self::SWEEP_EVERY): SagaWorker
    {
        return new SagaWorker(
            $this->relayUseCase(),
            $this->replyConsumer(),
            $this->sweepUseCase(),
            $this->connection(),
            new NullLogger(),
            self::BATCH,
            self::T,
            self::T_START,
            self::WAIT,
            $sweepEvery,
        );
    }

    private function relayUseCase(): RelayPendingWelcomeEmails
    {
        return new RelayPendingWelcomeEmails(
            $this->reader,
            $this->createMock(WelcomeEmailMessageFactory::class),
            $this->createMock(WelcomeEmailRelay::class),
            $this->createMock(EnrollmentSagaWriter::class),
            $this->createMock(SagaMetricsRecorder::class),
        );
    }

    private function sweepUseCase(): SweepTimedOutSagas
    {
        $clock = new class implements Clock {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-20T12:00:00+00:00');
            }
        };

        return new SweepTimedOutSagas(
            $this->reader,
            $this->createMock(EnrollmentSagaWriter::class),
            $this->createMock(SubscriptionConfirmationWriter::class),
            $this->createMock(TransactionManager::class),
            $clock,
            $this->createMock(SagaMetricsRecorder::class),
        );
    }

    private function replyConsumer(): WelcomeEmailOutcomeConsumer
    {
        return new WelcomeEmailOutcomeConsumer(
            $this->createMock(MessageConsumer::class),
            new WelcomeEmailOutcomeMessageMapper(),
            $this->createMock(CommandBus::class),
            $this->createMock(SagaMetricsRecorder::class),
            new NullLogger(),
        );
    }

    private function connection(): RabbitConnection
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        return new RabbitConnection($channel);
    }
}
