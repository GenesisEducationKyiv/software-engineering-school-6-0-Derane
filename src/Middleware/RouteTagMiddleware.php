<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Observability\RouteContextInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Records the matched Slim route *pattern* into the request-scoped
 * {@see RouteContextInterface} so the outer {@see RequestMetricsMiddleware} can
 * use it as a bounded metric label.
 *
 * Runs inside the routing middleware (where the route is resolved) but the
 * metric is recorded by the outer middleware — that split is what lets RED also
 * count routing failures (404/405), which never reach this layer.
 *
 * @psalm-api
 */
final readonly class RouteTagMiddleware implements MiddlewareInterface
{
    public function __construct(private RouteContextInterface $correlation)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var RouteInterface|null $route — Slim sets this to the matched route */
        $route = $request->getAttribute(RouteContext::ROUTE);
        if ($route instanceof RouteInterface) {
            $this->correlation->setRoute($route->getPattern());
        }

        return $handler->handle($request);
    }
}
