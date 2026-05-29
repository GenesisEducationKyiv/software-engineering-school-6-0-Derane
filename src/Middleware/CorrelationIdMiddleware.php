<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Observability\CorrelationContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Establishes a correlation id for the request so every log line emitted while
 * handling it can be tied together in Kibana. Honours an inbound `X-Request-Id`
 * (e.g. from an upstream proxy) and echoes the id back on the response.
 *
 * Outermost middleware: the id must be set before any other layer logs, and
 * cleared afterwards so it never leaks into the next request on a reused worker.
 *
 * @psalm-api
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Request-Id';

    public function __construct(private CorrelationContext $correlation)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine(self::HEADER);
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
        }

        $this->correlation->start($id);

        try {
            return $handler->handle($request)->withHeader(self::HEADER, $id);
        } finally {
            $this->correlation->reset();
        }
    }
}
