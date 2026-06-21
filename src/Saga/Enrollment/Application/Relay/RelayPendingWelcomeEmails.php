<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\Relay;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The outbox-style relay use-case. Reads STARTED sagas, publishes SendWelcomeEmail
 * for each via the WelcomeEmailRelay (publisher confirms), and advances the saga
 * to AwaitingConfirmation ONLY after a confirmed publish.
 *
 * On a publish throw (unconfirmed publish) it records the relay failure
 * (attempts++ / last_error) and does NOT advance state — the relay re-tries the
 * STARTED row next tick. A failed publish for one saga does not abort the batch.
 *
 * The welcome_command_published_total counter is incremented at THIS call-site
 * (D1/D5 ownership) only after a confirmed publish — a throw skips it.
 *
 * @psalm-api
 */
final readonly class RelayPendingWelcomeEmails
{
    private const int DEFAULT_BATCH = 50;

    private LoggerInterface $logger;

    public function __construct(
        private EnrollmentSagaReader $reader,
        private WelcomeEmailMessageFactory $messageFactory,
        private WelcomeEmailRelay $relay,
        private EnrollmentSagaWriter $writer,
        private SagaMetricsRecorder $metrics,
        private EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function relay(int $limit = self::DEFAULT_BATCH): void
    {
        foreach ($this->reader->dueForRelay($limit) as $saga) {
            try {
                $this->relay->publish($this->messageFactory->forSaga($saga));
            } catch (\Throwable $e) {
                // Unconfirmed publish: record and leave the saga STARTED for retry.
                // recordRelayFailure is itself wrapped (M1) so a DB blip while
                // recording one failure does not abort the rest of the batch — the
                // docstring's batch-isolation guarantee holds; next tick re-reads
                // this STARTED saga regardless of whether the marker write landed.
                try {
                    $this->writer->recordRelayFailure($saga->id(), $e->getMessage());
                } catch (\Throwable $recordError) {
                    $this->logger->warning('Failed to record saga relay failure', [
                        'saga_id' => $saga->id()->value(),
                        'publish_error' => $e->getMessage(),
                        'record_error' => $recordError->getMessage(),
                    ]);
                }

                continue;
            }

            // markPublished + the published counter run ONLY after publish()
            // returns (a confirmed publish).
            $published = $this->writer->markPublished($saga->id());
            $this->metrics->recordWelcomeCommandPublished();

            if ($published) {
                // The confirmed publish advanced the durable row; mirror the
                // transition on the aggregate to emit WelcomePublished on the
                // in-process plane. The persisted awaiting_since anchor is the
                // writer's SQL NOW(); the value passed here is immaterial (the
                // aggregate is used only to source the domain event).
                $saga->markPublished(new \DateTimeImmutable());
                foreach ($saga->pullDomainEvents() as $event) {
                    $this->eventDispatcher->dispatch($event);
                }
            }
        }
    }
}
