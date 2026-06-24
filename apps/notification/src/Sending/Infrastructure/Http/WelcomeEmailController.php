<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Http;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeAlreadyFailedException;
use App\Sending\Infrastructure\Error\WelcomeRequestValidationException;
use App\Sending\Infrastructure\Sync\WelcomeEmailFactory;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Synchronous REST baseline for the welcome-email send; must keep the SAME catch
 * order as the gRPC twin so one ExceptionStatusMap drives both transports.
 */
final readonly class WelcomeEmailController
{
    public function __construct(
        private SendWelcomeEmailHandler $handler,
        private WelcomeEmailFactory $factory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $email = $this->factory->fromArray($this->decodeBody($request));

        try {
            $this->handler->handle($email);
        } catch (WelcomeAlreadyFailedException $e) {
            // Terminal-failed is a NORMAL business result (HTTP 200) that drives the
            // saga compensate — not a transport error.
            return $this->json($response, ['outcome' => 'failed', 'error' => $e->getMessage()]);
        }

        return $this->json($response, ['outcome' => 'sent']);
    }

    /**
     * @return array<array-key,mixed>
     */
    private function decodeBody(Request $request): array
    {
        try {
            $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Welcome email request body is not valid JSON', ['error' => $e->getMessage()]);
            throw new WelcomeRequestValidationException('Request body must be valid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new WelcomeRequestValidationException('Request body must be a JSON object.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(StatusCodeInterface::STATUS_OK)
            ->withHeader('Content-Type', 'application/json');
    }
}
