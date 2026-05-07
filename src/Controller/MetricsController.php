<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MetricsServiceInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @psalm-api */
final class MetricsController
{
    public function __construct(private MetricsServiceInterface $metricsService)
    {
    }

    public function __invoke(Request $_request, Response $response): Response
    {
        $response->getBody()->write($this->metricsService->collect());
        return $response->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
