<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Application\Relay;

use App\Saga\Enrollment\Application\Relay\RelayPendingWelcomeEmails;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\SagaState;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Shared\Domain\ValueObject\SagaId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RelayPendingWelcomeEmailsTest extends TestCase
{
    private const string UUID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    private EnrollmentSagaReader&MockObject $reader;
    private WelcomeEmailMessageFactory&MockObject $messageFactory;
    private WelcomeEmailRelay&MockObject $relay;
    private EnrollmentSagaWriter&MockObject $writer;
    private SagaMetricsRecorder&MockObject $metrics;
    private RelayPendingWelcomeEmails $useCase;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(EnrollmentSagaReader::class);
        $this->messageFactory = $this->createMock(WelcomeEmailMessageFactory::class);
        $this->relay = $this->createMock(WelcomeEmailRelay::class);
        $this->writer = $this->createMock(EnrollmentSagaWriter::class);
        $this->metrics = $this->createMock(SagaMetricsRecorder::class);

        $this->useCase = new RelayPendingWelcomeEmails(
            $this->reader,
            $this->messageFactory,
            $this->relay,
            $this->writer,
            $this->metrics
        );

        $this->messageFactory->method('forSaga')->willReturn($this->aMessage());
    }

    public function testIncrementsThePublishedCounterOnlyOnAConfirmedPublish(): void
    {
        $saga = $this->aStartedSaga();
        $this->reader->method('dueForRelay')->willReturn([$saga]);
        $this->writer->method('markPublished')->willReturn(true);

        $this->metrics->expects($this->once())->method('recordWelcomeCommandPublished');

        $this->useCase->relay();
    }

    public function testDoesNotIncrementThePublishedCounterWhenPublishThrows(): void
    {
        $saga = $this->aStartedSaga();
        $this->reader->method('dueForRelay')->willReturn([$saga]);
        $this->relay->method('publish')->willThrowException(new \RuntimeException('unconfirmed'));

        $this->metrics->expects($this->never())->method('recordWelcomeCommandPublished');

        $this->useCase->relay();
    }

    public function testMarkPublishedRunsOnlyAfterPublishReturns(): void
    {
        $saga = $this->aStartedSaga();
        $this->reader->method('dueForRelay')->willReturn([$saga]);

        $order = [];
        $this->relay->expects($this->once())->method('publish')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'publish';
            });
        $this->writer->expects($this->once())->method('markPublished')
            ->with($this->equalTo($saga->id()))
            ->willReturnCallback(static function () use (&$order): bool {
                $order[] = 'markPublished';

                return true;
            });
        $this->writer->expects($this->never())->method('recordRelayFailure');

        $this->useCase->relay();

        $this->assertSame(['publish', 'markPublished'], $order);
    }

    public function testOnPublishThrowRecordsFailureAndDoesNotAdvanceState(): void
    {
        $saga = $this->aStartedSaga();
        $this->reader->method('dueForRelay')->willReturn([$saga]);

        $this->relay->method('publish')->willThrowException(new \RuntimeException('unconfirmed'));
        $this->writer->expects($this->never())->method('markPublished');
        $this->writer->expects($this->once())->method('recordRelayFailure')
            ->with($this->equalTo($saga->id()), 'unconfirmed');

        $this->useCase->relay();
    }

    public function testAFailedSagaDoesNotAbortTheBatch(): void
    {
        $bad = $this->aStartedSaga();
        $good = EnrollmentSaga::reconstitute(
            SagaId::fromString('11111111-1111-4111-8111-111111111111'),
            2,
            SagaState::Started,
            null
        );
        $this->reader->method('dueForRelay')->willReturn([$bad, $good]);

        $this->relay->method('publish')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('boom')),
            null
        );
        $this->writer->expects($this->once())->method('recordRelayFailure');
        $this->writer->expects($this->once())->method('markPublished')->with($this->equalTo($good->id()));

        $this->useCase->relay();
    }

    public function testAThrowFromRecordRelayFailureDoesNotAbortTheRemainingBatch(): void
    {
        // M1: the docstring promises one saga's failed publish does not abort the
        // batch. If recordRelayFailure itself throws (a DB blip), the use-case must
        // still process the remaining due sagas this tick rather than escaping the
        // foreach.
        $bad = $this->aStartedSaga();
        $good = EnrollmentSaga::reconstitute(
            SagaId::fromString('11111111-1111-4111-8111-111111111111'),
            2,
            SagaState::Started,
            null
        );
        $this->reader->method('dueForRelay')->willReturn([$bad, $good]);

        $this->relay->method('publish')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('unconfirmed')),
            null
        );
        $this->writer->method('recordRelayFailure')
            ->willThrowException(new \RuntimeException('db blip while recording'));

        // The good saga is still relayed and marked published.
        $this->writer->expects($this->once())->method('markPublished')->with($this->equalTo($good->id()));
        $this->metrics->expects($this->once())->method('recordWelcomeCommandPublished');

        $this->useCase->relay();
    }

    private function aStartedSaga(): EnrollmentSaga
    {
        return EnrollmentSaga::reconstitute(
            SagaId::fromString(self::UUID),
            1,
            SagaState::Started,
            null
        );
    }

    private function aMessage(): SendWelcomeEmail
    {
        return new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            self::UUID,
            1,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new \DateTimeImmutable('2026-06-20T12:00:00+00:00')
        );
    }
}
