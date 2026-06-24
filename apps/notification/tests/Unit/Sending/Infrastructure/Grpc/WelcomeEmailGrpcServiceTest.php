<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Grpc;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService;
use App\Sending\Infrastructure\Sync\WelcomeEmailFactory;
use Notification\Welcome\V1\Outcome;
use Notification\Welcome\V1\SendWelcomeEmailRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\ServiceException;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * In-process gRPC server test (RD8b): the real {@see WelcomeEmailGrpcService} wired
 * to a REAL {@see SendWelcomeEmailHandler} over doubled domain ports (the project
 * pattern — the handler is final readonly and cannot be doubled), a real
 * {@see ExceptionStatusMap}, and a mocked {@see ContextInterface}. Drives each
 * handler disposition through the ledger/mailer/publisher mocks and asserts the
 * adapter's outcome derivation + gRPC status mapping (RD6).
 */
final class WelcomeEmailGrpcServiceTest extends TestCase
{
    /** @var WelcomeNotificationLedger&MockObject */
    private WelcomeNotificationLedger $ledger;
    /** @var EmailRenderer&MockObject */
    private EmailRenderer $renderer;
    /** @var Mailer&MockObject */
    private Mailer $mailer;
    /** @var WelcomeOutcomePublisher&MockObject */
    private WelcomeOutcomePublisher $publisher;
    /** @var WelcomeProcessingStatsRecorder&MockObject */
    private WelcomeProcessingStatsRecorder $stats;
    /** @var ContextInterface&MockObject */
    private ContextInterface $ctx;

    #[\Override]
    protected function setUp(): void
    {
        $this->ledger = $this->createMock(WelcomeNotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->publisher = $this->createMock(WelcomeOutcomePublisher::class);
        $this->stats = $this->createMock(WelcomeProcessingStatsRecorder::class);
        $this->ctx = $this->createMock(ContextInterface::class);
    }

    private function service(): WelcomeEmailGrpcService
    {
        $handler = new SendWelcomeEmailHandler(
            $this->ledger,
            $this->renderer,
            $this->mailer,
            $this->publisher,
            $this->stats,
        );

        return new WelcomeEmailGrpcService(
            $handler,
            new WelcomeEmailFactory(),
            new ExceptionStatusMap(),
            new NullLogger(),
        );
    }

    private function validRequest(): SendWelcomeEmailRequest
    {
        return new SendWelcomeEmailRequest([
            'saga_id' => 'saga-1',
            'subscription_id' => 42,
            'email' => 'user@example.com',
            'repository' => 'owner/repo',
        ]);
    }

    public function testNormalReturnYieldsOutcomeSent(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->expects(self::once())->method('send');
        $this->ledger->method('markSent')->willReturn(true);
        $this->publisher->expects(self::once())->method('publish');

        $response = $this->service()->SendWelcomeEmail($this->ctx, $this->validRequest());

        self::assertSame(Outcome::OUTCOME_SENT, $response->getOutcome());
        self::assertSame('', $response->getErrorDetail());
    }

    public function testAlreadyFailedYieldsOutcomeFailedAsNormalResponse(): void
    {
        // AlreadyFailed claim → handler re-publishes `failed` then throws
        // WelcomeAlreadyFailedException → adapter returns OUTCOME_FAILED (no exception).
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadyFailed());
        $this->mailer->expects(self::never())->method('send');

        $response = $this->service()->SendWelcomeEmail($this->ctx, $this->validRequest());

        self::assertSame(Outcome::OUTCOME_FAILED, $response->getOutcome());
        self::assertNotSame('', $response->getErrorDetail());
    }

    public function testInFlightThrowsAbortedGrpcException(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::inFlight());

        try {
            $this->service()->SendWelcomeEmail($this->ctx, $this->validRequest());
            self::fail('Expected a GRPCException');
        } catch (GRPCException $e) {
            // Benign contention — ABORTED, not UNAVAILABLE (RD6).
            self::assertSame(StatusCode::ABORTED, $e->getCode());
            self::assertNotInstanceOf(ServiceException::class, $e);
        }
    }

    public function testMalformedRequestThrowsInvalidArgument(): void
    {
        $this->ledger->expects(self::never())->method('claim');

        $request = new SendWelcomeEmailRequest([
            'saga_id' => 'saga-1',
            'subscription_id' => 42,
            'email' => 'not-an-email',
            'repository' => 'owner/repo',
        ]);

        try {
            $this->service()->SendWelcomeEmail($this->ctx, $request);
            self::fail('Expected a GRPCException');
        } catch (GRPCException $e) {
            self::assertSame(StatusCode::INVALID_ARGUMENT, $e->getCode());
        }
    }

    public function testTransientSendFailureThrowsUnavailable(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP timeout'));
        $this->ledger->method('recordFailedAttempt');

        try {
            $this->service()->SendWelcomeEmail($this->ctx, $this->validRequest());
            self::fail('Expected a GRPCException');
        } catch (GRPCException $e) {
            self::assertSame(StatusCode::UNAVAILABLE, $e->getCode());
            self::assertNotInstanceOf(ServiceException::class, $e);
        }
    }

    public function testUnexpectedErrorThrowsServiceExceptionInternal(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send');
        $this->ledger->method('markSent')->willReturn(true);
        // A non-RuntimeException escaping the publish leg → INTERNAL → ServiceException.
        $this->publisher->method('publish')->willThrowException(new \LogicException('invariant broken'));

        try {
            $this->service()->SendWelcomeEmail($this->ctx, $this->validRequest());
            self::fail('Expected a ServiceException');
        } catch (ServiceException $e) {
            self::assertSame(StatusCode::INTERNAL, $e->getCode());
        }
    }
}
