<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Http;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Sending\Infrastructure\Http\WelcomeEmailController;
use App\Sending\Infrastructure\Sync\WelcomeEmailFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * REST baseline: real WelcomeEmailController over a real SendWelcomeEmailHandler;
 * error-path status mapping asserted through the real ErrorHandlerMiddleware.
 */
final class WelcomeEmailControllerTest extends TestCase
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

    #[\Override]
    protected function setUp(): void
    {
        $this->ledger = $this->createMock(WelcomeNotificationLedger::class);
        $this->renderer = $this->createMock(EmailRenderer::class);
        $this->mailer = $this->createMock(Mailer::class);
        $this->publisher = $this->createMock(WelcomeOutcomePublisher::class);
        $this->stats = $this->createMock(WelcomeProcessingStatsRecorder::class);
    }

    private function controller(): WelcomeEmailController
    {
        $handler = new SendWelcomeEmailHandler(
            $this->ledger,
            $this->renderer,
            $this->mailer,
            $this->publisher,
            $this->stats,
        );

        return new WelcomeEmailController($handler, new WelcomeEmailFactory(), new NullLogger());
    }

    /** @param array<string,mixed> $payload */
    private function request(array $payload): ServerRequestInterface
    {
        $body = (new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR));

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/internal/welcome-emails')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    /** @return array<string,mixed> */
    private function validPayload(): array
    {
        return [
            'sagaId' => 'saga-1',
            'subscriptionId' => 123,
            'email' => 'user@example.com',
            'repository' => 'owner/repo',
        ];
    }

    public function testValidRequestReturnsSentJson(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->expects(self::once())->method('send');
        $this->ledger->method('markSent')->willReturn(true);

        $response = ($this->controller())(
            $this->request($this->validPayload()),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['outcome' => 'sent'], json_decode((string) $response->getBody(), true));
    }

    public function testAlreadyFailedReturnsFailedJsonWith200(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::alreadyFailed());
        $this->mailer->expects(self::never())->method('send');

        $response = ($this->controller())(
            $this->request($this->validPayload()),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertSame('failed', $decoded['outcome']);
        self::assertArrayHasKey('error', $decoded);
        self::assertNotSame('', $decoded['error']);
    }

    public function testInFlightMapsTo409ViaErrorHandler(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::inFlight());

        $response = $this->runThroughErrorHandler($this->validPayload());

        self::assertSame(409, $response->getStatusCode());
    }

    public function testMalformedEmailMapsTo400ViaErrorHandler(): void
    {
        $this->ledger->expects(self::never())->method('claim');

        $payload = $this->validPayload();
        $payload['email'] = 'not-an-email';

        $response = $this->runThroughErrorHandler($payload);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testMissingFieldMapsTo400ViaErrorHandler(): void
    {
        $this->ledger->expects(self::never())->method('claim');

        $payload = $this->validPayload();
        unset($payload['repository']);

        $response = $this->runThroughErrorHandler($payload);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testTransientFailureMapsTo503ViaErrorHandler(): void
    {
        $this->ledger->method('claim')->willReturn(ClaimResult::claimed('fence'));
        $this->renderer->method('render')->willReturn(new RenderedEmail('s', 'h', 't'));
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));
        $this->ledger->method('recordFailedAttempt');

        $response = $this->runThroughErrorHandler($this->validPayload());

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * Runs the controller behind the real ErrorHandlerMiddleware: the controller lets
     * the throw propagate, so status mapping is exercised as in http/index.php.
     *
     * @param array<string,mixed> $payload
     */
    private function runThroughErrorHandler(array $payload): ResponseInterface
    {
        $middleware = new ErrorHandlerMiddleware(
            new NullLogger(),
            new ResponseFactory(),
            new ExceptionStatusMap(),
        );

        $controller = $this->controller();
        $handler = new class ($controller) implements RequestHandlerInterface {
            public function __construct(private readonly WelcomeEmailController $controller)
            {
            }

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->controller)($request, (new ResponseFactory())->createResponse());
            }
        };

        return $middleware->process($this->request($payload), $handler);
    }
}
