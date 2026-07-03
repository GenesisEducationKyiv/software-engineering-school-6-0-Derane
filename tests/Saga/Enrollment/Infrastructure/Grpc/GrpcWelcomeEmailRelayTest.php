<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Grpc;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Infrastructure\Grpc\GrpcWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\SyncWelcomeSendException;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use Grpc\UnaryCall;
use Notification\Welcome\V1\Outcome;
use Notification\Welcome\V1\SendWelcomeEmailRequest;
use Notification\Welcome\V1\SendWelcomeEmailResponse;
use Notification\Welcome\V1\WelcomeEmailServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Client + CommandBus are mocked so the wire mapping + retry policy are proven without ext-grpc.
 *
 * gRPC status codes (mirror \Grpc\STATUS_*): OK=0, INVALID_ARGUMENT=3,
 * DEADLINE_EXCEEDED=4, ABORTED=10, INTERNAL=13, UNAVAILABLE=14.
 */
final class GrpcWelcomeEmailRelayTest extends TestCase
{
    private const int OK = 0;
    private const int INVALID_ARGUMENT = 3;
    private const int DEADLINE_EXCEEDED = 4;
    private const int ABORTED = 10;
    private const int UNIMPLEMENTED = 12;
    private const int INTERNAL = 13;
    private const int UNAVAILABLE = 14;

    public function testOkSentDispatchesSentCommandAndIssuesAMappedUnaryCall(): void
    {
        $captured = [];
        $client = $this->clientReturning(
            [$this->responseWithOutcome(Outcome::OUTCOME_SENT), $this->grpcStatus(self::OK)],
            $captured,
        );
        $dispatched = [];
        $bus = $this->capturingBus($dispatched);

        $this->relay($client, $bus)->publish($this->message());

        self::assertCount(1, $captured);
        $request = $captured[0]['request'];
        self::assertInstanceOf(SendWelcomeEmailRequest::class, $request);
        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $request->getSagaId());
        self::assertSame('42', (string) $request->getSubscriptionId());
        self::assertSame('user@example.test', $request->getEmail());
        self::assertSame('owner/repo', $request->getRepository());
        // 10s per-call deadline, in microseconds.
        self::assertSame(10_000_000, $captured[0]['options']['timeout']);

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(HandleWelcomeEmailOutcomeCommand::class, $dispatched[0]);
        self::assertSame(WelcomeOutcome::Sent, $dispatched[0]->outcome);
        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $dispatched[0]->sagaId);
        self::assertSame(42, $dispatched[0]->subscriptionId);
    }

    public function testOkFailedDispatchesFailedCommand(): void
    {
        $captured = [];
        $client = $this->clientReturning(
            [$this->responseWithOutcome(Outcome::OUTCOME_FAILED), $this->grpcStatus(self::OK)],
            $captured,
        );
        $dispatched = [];
        $bus = $this->capturingBus($dispatched);

        $this->relay($client, $bus)->publish($this->message());

        self::assertCount(1, $dispatched);
        self::assertSame(WelcomeOutcome::Failed, $dispatched[0]->outcome);
    }

    public function testAbortedThrowsAndNeverDrivesTheSaga(): void
    {
        $captured = [];
        $client = $this->clientReturning([null, $this->grpcStatus(self::ABORTED, 'in flight')], $captured);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            // ABORTED is benign in-flight contention: exactly one call, never retried.
            self::assertCount(1, $captured);
        }
    }

    public function testInvalidArgumentThrowsWithoutRetryOrDispatch(): void
    {
        $captured = [];
        $client = $this->clientReturning([null, $this->grpcStatus(self::INVALID_ARGUMENT, 'bad email')], $captured);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            self::assertCount(1, $captured);
        }
    }

    public function testUnavailableRetriesThenThrows(): void
    {
        $captured = [];
        $client = $this->clientReturning([null, $this->grpcStatus(self::UNAVAILABLE, 'down')], $captured);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            // UNAVAILABLE is transient: retried up to 3 attempts.
            self::assertCount(3, $captured);
        }
    }

    public function testUnavailableTwiceThenOkSentReturnsAfterRetry(): void
    {
        $captured = [];
        $client = $this->clientReturningSequence([
            [null, $this->grpcStatus(self::UNAVAILABLE, 'down')],
            [null, $this->grpcStatus(self::DEADLINE_EXCEEDED, 'slow')],
            [$this->responseWithOutcome(Outcome::OUTCOME_SENT), $this->grpcStatus(self::OK)],
        ], $captured);
        $dispatched = [];
        $bus = $this->capturingBus($dispatched);

        $this->relay($client, $bus)->publish($this->message());

        self::assertCount(3, $captured);
        self::assertCount(1, $dispatched);
        self::assertSame(WelcomeOutcome::Sent, $dispatched[0]->outcome);
    }

    public function testInternalThrowsWithoutRetry(): void
    {
        $captured = [];
        $client = $this->clientReturning([null, $this->grpcStatus(self::INTERNAL, 'boom')], $captured);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            self::assertCount(1, $captured);
        }
    }

    public function testPermanentNonOkCodeThrowsWithoutRetryOrDispatch(): void
    {
        // A code outside the transient allow-list (here UNIMPLEMENTED) must surface
        // immediately, not burn the retry budget.
        $captured = [];
        $client = $this->clientReturning([null, $this->grpcStatus(self::UNIMPLEMENTED, 'no such method')], $captured);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            self::assertCount(1, $captured);
        }
    }

    public function testUnspecifiedOutcomeOnOkStatusThrowsWithoutRetryOrDispatch(): void
    {
        // proto3 enum-zero defence: an OK status carrying OUTCOME_UNSPECIFIED is a contract
        // breach — map it to a non-retryable failure, never a saga drive (the call returned OK).
        $captured = [];
        $client = $this->clientReturning(
            [$this->responseWithOutcome(Outcome::OUTCOME_UNSPECIFIED), $this->grpcStatus(self::OK)],
            $captured,
        );
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($client, $bus)->publish($this->message());
        } finally {
            self::assertCount(1, $captured);
        }
    }

    /**
     * @param array<int, HandleWelcomeEmailOutcomeCommand> $dispatched
     */
    private function capturingBus(array &$dispatched): CommandBus
    {
        return new class ($dispatched) implements CommandBus {
            /** @param array<int, HandleWelcomeEmailOutcomeCommand> $dispatched */
            public function __construct(private array &$dispatched)
            {
            }

            #[\Override]
            public function dispatch(Command $command): void
            {
                /** @var HandleWelcomeEmailOutcomeCommand $command */
                $this->dispatched[] = $command;
            }
        };
    }

    /**
     * Every SendWelcomeEmail call yields the same [$response, $status].
     *
     * @param array{0: SendWelcomeEmailResponse|null, 1: \stdClass} $result
     * @param array<int, array{request: SendWelcomeEmailRequest, options: array<string, mixed>}> $captured
     */
    private function clientReturning(array $result, array &$captured): WelcomeEmailServiceClient
    {
        $client = $this->createMock(WelcomeEmailServiceClient::class);
        $client->method('SendWelcomeEmail')->willReturnCallback(
            /**
             * @param array<array-key, mixed> $metadata
             * @param array<string, mixed> $options
             */
            function (
                SendWelcomeEmailRequest $request,
                array $metadata,
                array $options
            ) use (
                $result,
                &$captured
            ): UnaryCall {
                $captured[] = ['request' => $request, 'options' => $options];

                return $this->unaryCall($result);
            }
        );

        return $client;
    }

    /**
     * Successive SendWelcomeEmail calls yield the given sequence.
     *
     * @param list<array{0: SendWelcomeEmailResponse|null, 1: \stdClass}> $sequence
     * @param array<int, array{request: SendWelcomeEmailRequest, options: array<string, mixed>}> $captured
     */
    private function clientReturningSequence(array $sequence, array &$captured): WelcomeEmailServiceClient
    {
        $client = $this->createMock(WelcomeEmailServiceClient::class);
        $i = 0;
        $client->method('SendWelcomeEmail')->willReturnCallback(
            /**
             * @param array<array-key, mixed> $metadata
             * @param array<string, mixed> $options
             */
            function (
                SendWelcomeEmailRequest $request,
                array $metadata,
                array $options
            ) use (
                $sequence,
                &$captured,
                &$i
            ): UnaryCall {
                $captured[] = ['request' => $request, 'options' => $options];
                $result = $sequence[$i] ?? $sequence[count($sequence) - 1];
                $i++;

                return $this->unaryCall($result);
            }
        );

        return $client;
    }

    /**
     * @param array{0: SendWelcomeEmailResponse|null, 1: \stdClass} $result
     */
    private function unaryCall(array $result): UnaryCall
    {
        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn($result);

        return $call;
    }

    private function grpcStatus(int $code, string $details = ''): \stdClass
    {
        $status = new \stdClass();
        $status->code = $code;
        $status->details = $details;
        $status->metadata = [];

        return $status;
    }

    private function responseWithOutcome(int $outcome): SendWelcomeEmailResponse
    {
        return new SendWelcomeEmailResponse(['outcome' => $outcome]);
    }

    private function relay(WelcomeEmailServiceClient $client, CommandBus $bus): GrpcWelcomeEmailRelay
    {
        // Zero backoff keeps the retry tests fast.
        return new GrpcWelcomeEmailRelay($client, $bus, 10, 3, [0, 0, 0]);
    }

    private function message(): SendWelcomeEmail
    {
        return new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            42,
            new EmailAddress('user@example.test'),
            new RepositoryName('owner/repo'),
            new \DateTimeImmutable('2026-06-23T10:00:00+00:00'),
        );
    }
}
