<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Grpc;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\SyncWelcomeSendException;
use App\Shared\Domain\Bus\Command\CommandBus;
use Notification\Welcome\V1\Outcome;
use Notification\Welcome\V1\SendWelcomeEmailRequest;
use Notification\Welcome\V1\SendWelcomeEmailResponse;
use Notification\Welcome\V1\WelcomeEmailServiceClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The `grpc` synchronous welcome-send transport (opt-in via WELCOME_EMAIL_TRANSPORT,
 * RD4). It IMPLEMENTS the existing WelcomeEmailRelay port (publish: void) so the
 * SagaWorker + RelayPendingWelcomeEmails use-case are untouched — only the DI binding
 * swaps (RD4 impl-swap, superseding the architecture's earlier sibling-port idea D5).
 *
 * Unlike the async rabbit relay, this BLOCKS for the sent|failed outcome (caller-waits,
 * DC1) and applies the outcome IN-THREAD by dispatching the existing
 * HandleWelcomeEmailOutcomeCommand through the in-house CommandBus. publish() returns
 * void only on a definitive OK outcome (the relay then markPublished()-no-ops on the
 * already-advanced row; the async WelcomeEmailOutcome reply Service B still publishes is
 * an idempotent no-op backstop — RD5). On benign in-flight contention (ABORTED),
 * a validation/internal error, or transport failure after the bounded retry budget, it
 * THROWS SyncWelcomeSendException so the relay records the failure and leaves the saga
 * for the next tick — exactly the broker-buffer/sweeper replacement on the sync path
 * (arch §7.4, RD6).
 *
 * The WelcomeEmailServiceClient is INJECTED so tests can mock it without ext-grpc
 * (which exists only in the monolith image). The status-code integers below mirror the
 * \Grpc\STATUS_* runtime constants (ext-grpc) by value; we use typed constants so this
 * adapter stays analyzable/testable on a host without ext-grpc loaded.
 *
 * @psalm-api
 */
final readonly class GrpcWelcomeEmailRelay implements WelcomeEmailRelay
{
    private const string TRANSPORT = 'grpc';

    // gRPC canonical status codes (mirror \Grpc\STATUS_* from ext-grpc, by value).
    private const int STATUS_OK = 0;
    private const int STATUS_DEADLINE_EXCEEDED = 4;
    private const int STATUS_ABORTED = 10;
    private const int STATUS_INTERNAL = 13;
    private const int STATUS_UNAVAILABLE = 14;

    private LoggerInterface $logger;

    /**
     * @param non-empty-list<int> $backoffMs per-attempt backoff before the NEXT attempt
     */
    public function __construct(
        private WelcomeEmailServiceClient $client,
        private CommandBus $commandBus,
        private int $deadlineSeconds = 10,
        private int $maxAttempts = 3,
        private array $backoffMs = [200, 500, 1000],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[\Override]
    public function publish(SendWelcomeEmail $message): void
    {
        $outcome = $this->sendWithRetry($this->toRequest($message));

        // Definitive OK only: apply the outcome in-thread via the existing reply-path
        // command. confirm/cancel + saga transition are conditional + idempotent (RD5).
        $this->commandBus->dispatch(new HandleWelcomeEmailOutcomeCommand(
            $message->sagaId,
            $message->subscriptionId,
            $outcome,
        ));

        $this->logger->info('welcome email sent via grpc', [
            'saga_id' => $message->sagaId,
            'subscription_id' => $message->subscriptionId,
            'repository' => $message->repository->value(),
            'outcome' => $outcome->value,
        ]);
    }

    private function sendWithRetry(SendWelcomeEmailRequest $request): WelcomeOutcome
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            /** @var array{0: SendWelcomeEmailResponse|null, 1: \stdClass} $result */
            $result = $this->client
                ->SendWelcomeEmail($request, [], ['timeout' => $this->deadlineSeconds * 1_000_000])
                ->wait();

            [$response, $status] = $result;
            /** @var int $code */
            $code = $status->code ?? self::STATUS_INTERNAL;
            /** @var string $detail */
            $detail = $status->details ?? '';

            if ($code === self::STATUS_OK && $response instanceof SendWelcomeEmailResponse) {
                return $this->mapOutcome($response->getOutcome());
            }

            // ABORTED = benign in-flight contention: never retry, never drive the saga.
            if ($code === self::STATUS_ABORTED) {
                throw SyncWelcomeSendException::benignContention(self::TRANSPORT, $detail);
            }

            // Transient ONLY — an explicit allow-list (the inverse of RestWelcomeEmailRelay's
            // isTransient): UNAVAILABLE / DEADLINE_EXCEEDED retry within the budget. EVERY
            // other non-OK code (INVALID_ARGUMENT, INTERNAL, NOT_FOUND, UNIMPLEMENTED,
            // PERMISSION_DENIED, …) is deterministic — surface it immediately rather than
            // burn the full retry budget and mask its cause behind "failed after N attempts".
            if ($code === self::STATUS_UNAVAILABLE || $code === self::STATUS_DEADLINE_EXCEEDED) {
                $lastError = new \RuntimeException(sprintf('grpc status %d: %s', $code, $detail));
                $this->backoff($attempt);
                continue;
            }

            throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('status %d: %s', $code, $detail),
            );
        }

        throw SyncWelcomeSendException::transportExhausted(self::TRANSPORT, $this->maxAttempts, $lastError);
    }

    private function toRequest(SendWelcomeEmail $message): SendWelcomeEmailRequest
    {
        return new SendWelcomeEmailRequest([
            'saga_id' => $message->sagaId,
            'subscription_id' => $message->subscriptionId,
            'email' => $message->email->value(),
            'repository' => $message->repository->value(),
        ]);
    }

    private function mapOutcome(int $wireOutcome): WelcomeOutcome
    {
        return match ($wireOutcome) {
            Outcome::OUTCOME_SENT => WelcomeOutcome::Sent,
            Outcome::OUTCOME_FAILED => WelcomeOutcome::Failed,
            default => throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('server returned unspecified outcome %d on OK status', $wireOutcome),
            ),
        };
    }

    private function backoff(int $attempt): void
    {
        if ($attempt >= $this->maxAttempts) {
            return;
        }
        $index = min($attempt - 1, count($this->backoffMs) - 1);
        $sleepMs = $this->backoffMs[$index];
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}
