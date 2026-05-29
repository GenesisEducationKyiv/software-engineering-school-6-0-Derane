<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Observability\CorrelationContext;
use App\Observability\Metrics\HttpMetrics;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Records RED metrics for every HTTP request and emits a structured access log.
 *
 * Sits OUTSIDE the routing + error-handling middleware so it observes the final
 * response for *every* request — including routing failures (404/405) that the
 * router raises before the handler runs. The status label is taken from the
 * response the error handler produced, and the route label from the pattern
 * {@see RouteTagMiddleware} recorded inside routing (or `unmatched`).
 *
 * @psalm-api
 */
final readonly class RequestMetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HttpMetrics $metrics,
        private CorrelationContext $correlation,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $status = StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR;
        $start = microtime(true);

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            $durationSeconds = microtime(true) - $start;
            $route = $this->correlation->route() ?? 'unmatched';
            $this->metrics->observe($method, $route, $status, $durationSeconds);
            $this->logger->info('http request handled', [
                'http_method' => $method,
                'route' => $route,
                'status' => $status,
                'duration_ms' => round($durationSeconds * 1000.0, 2),
            ]);
        }
    }
}
