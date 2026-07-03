<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rest;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\SyncWelcomeSendException;
use App\Shared\Domain\Bus\Command\CommandBus;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The `rest` synchronous welcome-send transport: POSTs the send to Service B, blocks for
 * the reply, and applies the outcome in-thread via the CommandBus. Retries only transient
 * transport errors; a 409 or deterministic 4xx/5xx throws immediately.
 *
 * @psalm-api
 */
final readonly class RestWelcomeEmailRelay implements WelcomeEmailRelay
{
    private const string TRANSPORT = 'rest';
    private const int HTTP_OK = 200;
    private const int HTTP_MULTIPLE_CHOICES = 300;
    private const int HTTP_CONFLICT = 409;
    private const int HTTP_BAD_GATEWAY = 502;
    private const int HTTP_SERVICE_UNAVAILABLE = 503;
    private const int HTTP_GATEWAY_TIMEOUT = 504;

    private LoggerInterface $logger;

    /**
     * @param non-empty-list<int> $backoffMs per-attempt backoff before the NEXT attempt
     */
    public function __construct(
        private ClientInterface $httpClient,
        private CommandBus $commandBus,
        private string $endpoint,
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
        $outcome = $this->sendWithRetry($message);

        $this->commandBus->dispatch(new HandleWelcomeEmailOutcomeCommand(
            $message->sagaId,
            $message->subscriptionId,
            $outcome,
        ));

        $this->logger->info('welcome email sent via rest', [
            'saga_id' => $message->sagaId,
            'subscription_id' => $message->subscriptionId,
            'repository' => $message->repository->value(),
            'outcome' => $outcome->value,
        ]);
    }

    private function sendWithRetry(SendWelcomeEmail $message): WelcomeOutcome
    {
        $body = [
            'sagaId' => $message->sagaId,
            'subscriptionId' => $message->subscriptionId,
            'email' => $message->email->value(),
            'repository' => $message->repository->value(),
        ];

        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $this->endpoint, [
                    'json' => $body,
                    'timeout' => $this->deadlineSeconds,
                    'connect_timeout' => $this->deadlineSeconds,
                    'http_errors' => false,
                ]);
            } catch (ConnectException $e) {
                $lastError = $e;
                $this->backoff($attempt);
                continue;
            }

            $code = $response->getStatusCode();

            if ($code >= self::HTTP_OK && $code < self::HTTP_MULTIPLE_CHOICES) {
                return $this->parseOutcome($response);
            }

            // 409 = benign in-flight contention: never retry, never drive the saga.
            if ($code === self::HTTP_CONFLICT) {
                throw SyncWelcomeSendException::benignContention(
                    self::TRANSPORT,
                    sprintf('HTTP 409: %s', $this->bodyText($response)),
                );
            }

            if ($this->isTransient($code)) {
                $lastError = new \RuntimeException(sprintf('HTTP %d: %s', $code, $this->bodyText($response)));
                $this->backoff($attempt);
                continue;
            }

            // Deterministic 4xx/5xx (validation / internal): won't improve on retry.
            throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('HTTP %d: %s', $code, $this->bodyText($response)),
            );
        }

        throw SyncWelcomeSendException::transportExhausted(self::TRANSPORT, $this->maxAttempts, $lastError);
    }

    private function parseOutcome(ResponseInterface $response): WelcomeOutcome
    {
        $raw = $this->bodyText($response);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('non-JSON 2xx reply: %s', $raw),
                $e,
            );
        }

        if (!is_array($decoded) || !isset($decoded['outcome']) || !is_string($decoded['outcome'])) {
            throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('2xx reply missing string "outcome": %s', $raw),
            );
        }

        $outcome = WelcomeOutcome::tryFrom($decoded['outcome']);
        if ($outcome === null) {
            throw SyncWelcomeSendException::nonRetryable(
                self::TRANSPORT,
                sprintf('2xx reply has unknown outcome "%s"', $decoded['outcome']),
            );
        }

        return $outcome;
    }

    private function isTransient(int $code): bool
    {
        return $code === self::HTTP_BAD_GATEWAY
            || $code === self::HTTP_SERVICE_UNAVAILABLE
            || $code === self::HTTP_GATEWAY_TIMEOUT;
    }

    private function bodyText(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $body->rewind();

        return $body->getContents();
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
