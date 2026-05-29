<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ExceptionStatusMap;
use App\Observability\Metrics\HttpMetrics;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Records RED metrics for every HTTP request and emits a structured access log.
 *
 * Runs inside the routing middleware so the matched route *pattern* is used as
 * the label (bounding cardinality), and reuses {@see ExceptionStatusMap} to
 * label the status of a thrown exception with the same code the error handler
 * will map it to, then re-throws so the error handler still builds the response.
 *
 * @psalm-api
 */
final readonly class RequestMetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HttpMetrics $metrics,
        private ExceptionStatusMap $statusMap,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $route = $this->routePattern($request);
        $status = StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR;
        $start = microtime(true);

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (\Throwable $e) {
            $status = $this->statusMap->toHttpStatus($e);
            throw $e;
        } finally {
            $durationSeconds = microtime(true) - $start;
            $this->metrics->observe($method, $route, $status, $durationSeconds);
            $this->logger->info('http request handled', [
                'http_method' => $method,
                'route' => $route,
                'status' => $status,
                'duration_ms' => round($durationSeconds * 1000.0, 2),
            ]);
        }
    }

    private function routePattern(ServerRequestInterface $request): string
    {
        /** @var RouteInterface|null $route — Slim sets this to the matched route, or it is absent */
        $route = $request->getAttribute(RouteContext::ROUTE);

        return $route instanceof RouteInterface ? $route->getPattern() : 'unmatched';
    }
}
