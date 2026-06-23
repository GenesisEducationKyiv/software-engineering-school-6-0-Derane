<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rest;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Infrastructure\Rest\RestWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\SyncWelcomeSendException;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * RestWelcomeEmailRelay is the `rest` sync transport that IMPLEMENTS the existing
 * WelcomeEmailRelay port (publish: void, RD4). It POSTs to /internal/welcome-emails,
 * blocks for {outcome,error}, and on a definitive 2xx drives the saga in-thread via
 * HandleWelcomeEmailOutcomeCommand on the CommandBus. Guzzle is mocked with a
 * MockHandler so the outcome mapping + deadline/retry policy are proven without a live
 * Service B (arch §7.2/§7.4).
 */
final class RestWelcomeEmailRelayTest extends TestCase
{
    private const string ENDPOINT = 'http://notification-svc:8081/internal/welcome-emails';

    public function testHttp200SentDispatchesSentCommand(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['outcome' => 'sent']))]);
        $dispatched = [];

        $this->relay($mock, $this->capturingBus($dispatched))->publish($this->message());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(HandleWelcomeEmailOutcomeCommand::class, $dispatched[0]);
        self::assertSame(WelcomeOutcome::Sent, $dispatched[0]->outcome);
        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $dispatched[0]->sagaId);
        self::assertSame(42, $dispatched[0]->subscriptionId);
    }

    public function testHttp200FailedDispatchesFailedCommandWithoutRetry(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['outcome' => 'failed', 'error' => 'terminal'])),
        ]);
        $dispatched = [];

        $this->relay($mock, $this->capturingBus($dispatched))->publish($this->message());

        self::assertCount(1, $dispatched);
        self::assertSame(WelcomeOutcome::Failed, $dispatched[0]->outcome);
        // A clean FAILED is a terminal business outcome — single call, never retried.
        self::assertCount(0, $mock);
    }

    public function testPostsTheSendWelcomeEmailFieldsAsJson(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['outcome' => 'sent']))]);
        $sent = [];
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$sent): callable {
            return function (Request $request, array $options) use ($handler, &$sent) {
                $sent[] = $request;

                return $handler($request, $options);
            };
        });
        $client = new Client(['handler' => $stack]);
        $dispatched = [];

        $relay = new RestWelcomeEmailRelay(
            $client,
            $this->capturingBus($dispatched),
            self::ENDPOINT,
            10,
            3,
            [0, 0, 0],
        );
        $relay->publish($this->message());

        self::assertCount(1, $sent);
        self::assertSame('POST', $sent[0]->getMethod());
        self::assertSame(self::ENDPOINT, (string) $sent[0]->getUri());
        $body = json_decode((string) $sent[0]->getBody(), true);
        self::assertSame([
            'sagaId' => '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            'subscriptionId' => 42,
            'email' => 'user@example.test',
            'repository' => 'owner/repo',
        ], $body);
    }

    public function testHttp409ThrowsWithoutRetryOrDispatch(): void
    {
        $mock = new MockHandler([new Response(409, [], 'in flight')]);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($mock, $bus)->publish($this->message());
        } finally {
            // 409 is benign contention: ONE call, no retry.
            self::assertCount(0, $mock);
        }
    }

    public function testHttp400ThrowsWithoutRetryOrDispatch(): void
    {
        $mock = new MockHandler([new Response(400, [], 'bad request')]);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        $this->relay($mock, $bus)->publish($this->message());
    }

    public function testHttp503TwiceThenOkReturnsAfterRetry(): void
    {
        $mock = new MockHandler([
            new Response(503, [], 'unavailable'),
            new Response(503, [], 'unavailable'),
            new Response(200, [], (string) json_encode(['outcome' => 'sent'])),
        ]);
        $dispatched = [];

        $this->relay($mock, $this->capturingBus($dispatched))->publish($this->message());

        self::assertCount(1, $dispatched);
        self::assertSame(WelcomeOutcome::Sent, $dispatched[0]->outcome);
        self::assertCount(0, $mock);
    }

    public function testHttp503OnAllAttemptsThrows(): void
    {
        $mock = new MockHandler([
            new Response(503, [], 'unavailable'),
            new Response(503, [], 'unavailable'),
            new Response(503, [], 'unavailable'),
        ]);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($mock, $bus)->publish($this->message());
        } finally {
            self::assertCount(0, $mock);
        }
    }

    public function testConnectExceptionRetriesThenThrows(): void
    {
        $request = new Request('POST', self::ENDPOINT);
        $mock = new MockHandler([
            new ConnectException('connect failed', $request),
            new ConnectException('connect failed', $request),
            new ConnectException('connect failed', $request),
        ]);
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(SyncWelcomeSendException::class);
        try {
            $this->relay($mock, $bus)->publish($this->message());
        } finally {
            self::assertCount(0, $mock);
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

    private function relay(MockHandler $mock, CommandBus $bus): RestWelcomeEmailRelay
    {
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        // Zero backoff so the retry tests stay fast.
        return new RestWelcomeEmailRelay($client, $bus, self::ENDPOINT, 10, 3, [0, 0, 0]);
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
