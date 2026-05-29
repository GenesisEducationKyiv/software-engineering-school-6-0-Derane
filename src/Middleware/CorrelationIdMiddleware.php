<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Observability\CorrelationContextInterface;
use App\Observability\CorrelationIdGeneratorInterface;
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

    public function __construct(
        private CorrelationContextInterface $correlation,
        private CorrelationIdGeneratorInterface $idGenerator
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine(self::HEADER);
        if ($id === '') {
            $id = $this->idGenerator->generate();
        }

        $this->correlation->start($id);

        try {
            return $handler->handle($request)->withHeader(self::HEADER, $id);
        } finally {
            $this->correlation->reset();
        }
    }
}
