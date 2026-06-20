<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Domain;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\Event\SagaCompensated;
use App\Saga\Enrollment\Domain\Event\SagaCompleted;
use App\Saga\Enrollment\Domain\Event\SagaStarted;
use App\Saga\Enrollment\Domain\Event\WelcomePublished;
use App\Saga\Enrollment\Domain\SagaState;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\SagaId;
use PHPUnit\Framework\TestCase;

final class EnrollmentSagaTest extends TestCase
{
    private const string UUID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    public function testStartRecordsExactlyOneSagaStartedAndSeedsState(): void
    {
        $saga = EnrollmentSaga::start(SagaId::fromString(self::UUID), 123);

        $this->assertSame(self::UUID, $saga->id()->value());
        $this->assertSame(123, $saga->subscriptionId());
        $this->assertSame(SagaState::Started, $saga->state());
        $this->assertNull($saga->awaitingSince());

        $events = $saga->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SagaStarted::class, $events[0]);
        $this->assertSame(self::UUID, $events[0]->sagaId);
        $this->assertSame(123, $events[0]->subscriptionId);
    }

    public function testPullingTwiceDrainsTheBuffer(): void
    {
        $saga = EnrollmentSaga::start(SagaId::fromString(self::UUID), 1);

        $this->assertCount(1, $saga->pullDomainEvents());
        $this->assertCount(0, $saga->pullDomainEvents());
    }

    public function testReconstituteRecordsNothing(): void
    {
        $saga = EnrollmentSaga::reconstitute(
            SagaId::fromString(self::UUID),
            7,
            SagaState::AwaitingConfirmation,
            new \DateTimeImmutable('2026-06-20T12:00:00+00:00')
        );

        $this->assertCount(0, $saga->pullDomainEvents());
        $this->assertSame(7, $saga->subscriptionId());
        $this->assertSame(SagaState::AwaitingConfirmation, $saga->state());
        $this->assertNotNull($saga->awaitingSince());
    }

    public function testMarkPublishedTransitionsStartedToAwaitingAndRecordsEvent(): void
    {
        $saga = EnrollmentSaga::start(SagaId::fromString(self::UUID), 1);
        $saga->pullDomainEvents();
        $awaitingSince = new \DateTimeImmutable('2026-06-20T12:00:00+00:00');

        $saga->markPublished($awaitingSince);

        $this->assertSame(SagaState::AwaitingConfirmation, $saga->state());
        $this->assertEquals($awaitingSince, $saga->awaitingSince());

        $events = $saga->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(WelcomePublished::class, $events[0]);
    }

    public function testMarkPublishedFromNonStartedStateIsRejected(): void
    {
        $saga = EnrollmentSaga::reconstitute(
            SagaId::fromString(self::UUID),
            1,
            SagaState::Completed,
            null
        );

        $this->expectException(InvalidArgumentException::class);

        $saga->markPublished(new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{0: SagaState}>
     */
    public static function completablePreStates(): iterable
    {
        yield 'from started (reply-before-relay)' => [SagaState::Started];
        yield 'from awaiting_confirmation (happy path)' => [SagaState::AwaitingConfirmation];
    }

    /**
     * @dataProvider completablePreStates
     */
    public function testCompleteAcceptsStartedAndAwaiting(SagaState $pre): void
    {
        $saga = EnrollmentSaga::reconstitute(SagaId::fromString(self::UUID), 1, $pre, null);

        $saga->complete();

        $this->assertSame(SagaState::Completed, $saga->state());
        $events = $saga->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SagaCompleted::class, $events[0]);
    }

    /**
     * @return iterable<string, array{0: SagaState}>
     */
    public static function nonCompletablePreStates(): iterable
    {
        yield 'completed' => [SagaState::Completed];
        yield 'compensating' => [SagaState::Compensating];
        yield 'compensated' => [SagaState::Compensated];
    }

    /**
     * @dataProvider nonCompletablePreStates
     */
    public function testCompleteFromTerminalOrCompensatingIsRejected(SagaState $pre): void
    {
        $saga = EnrollmentSaga::reconstitute(SagaId::fromString(self::UUID), 1, $pre, null);

        $this->expectException(InvalidArgumentException::class);

        $saga->complete();
    }

    /**
     * @dataProvider completablePreStates
     */
    public function testCompensateAcceptsStartedAndAwaiting(SagaState $pre): void
    {
        $saga = EnrollmentSaga::reconstitute(SagaId::fromString(self::UUID), 1, $pre, null);

        $saga->compensate();

        $this->assertSame(SagaState::Compensated, $saga->state());
        $events = $saga->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SagaCompensated::class, $events[0]);
    }

    /**
     * @dataProvider nonCompletablePreStates
     */
    public function testCompensateFromTerminalOrCompensatingIsRejected(SagaState $pre): void
    {
        $saga = EnrollmentSaga::reconstitute(SagaId::fromString(self::UUID), 1, $pre, null);

        $this->expectException(InvalidArgumentException::class);

        $saga->compensate();
    }

    public function testFullHappyPathRecordsEventsInOrder(): void
    {
        $saga = EnrollmentSaga::start(SagaId::fromString(self::UUID), 9);
        $saga->markPublished(new \DateTimeImmutable('2026-06-20T12:00:00+00:00'));
        $saga->complete();

        $events = $saga->pullDomainEvents();

        $this->assertCount(3, $events);
        $this->assertInstanceOf(SagaStarted::class, $events[0]);
        $this->assertInstanceOf(WelcomePublished::class, $events[1]);
        $this->assertInstanceOf(SagaCompleted::class, $events[2]);
    }
}
