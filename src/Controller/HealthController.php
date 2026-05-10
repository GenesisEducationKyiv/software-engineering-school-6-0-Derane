<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\HealthCheckInterface;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class HealthController
{
    public function __construct(
        private HealthCheckInterface $healthCheck,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $_request, Response $response): Response
    {
        try {
            $this->healthCheck->check();
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed', ['error' => $e->getMessage()]);
            $response->getBody()->write(json_encode(['status' => 'error'], JSON_THROW_ON_ERROR));
            return $response
                ->withStatus(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}
