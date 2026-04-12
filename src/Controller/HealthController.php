<?php

declare(strict_types=1);

namespace App\Controller;

use Fig\Http\Message\StatusCodeInterface;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class HealthController
{
    public function __construct(
        private PDO $pdo,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        try {
            $this->pdo->query('SELECT 1');
            $response->getBody()->write(json_encode(['status' => 'ok']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Health check failed', ['error' => $e->getMessage()]);
            $response->getBody()->write(json_encode(['status' => 'error']));
            return $response
                ->withStatus(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}
