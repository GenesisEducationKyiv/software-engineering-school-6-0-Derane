<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Http;

use App\Sending\Infrastructure\Metrics\MetricsServiceInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class MetricsController
{
    public function __construct(private MetricsServiceInterface $metrics)
    {
    }

    public function __invoke(Request $_request, Response $response): Response
    {
        $response->getBody()->write($this->metrics->collect());

        return $response->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
