<?php

declare(strict_types=1);

namespace App\Middleware;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class ApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $apiKey,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->apiKey)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        // Skip auth for metrics, health, and static files
        if (in_array($path, ['/metrics', '/health', '/'], true) || str_starts_with($path, '/static')) {
            return $handler->handle($request);
        }

        $providedKey = $request->getHeaderLine('X-API-Key');

        if ($providedKey === '') {
            $response = $this->responseFactory->createResponse();
            $response->getBody()->write(json_encode(['error' => 'API key is required'], JSON_THROW_ON_ERROR));
            return $response
                ->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED)
                ->withHeader('Content-Type', 'application/json');
        }

        if (!hash_equals($this->apiKey, $providedKey)) {
            $response = $this->responseFactory->createResponse();
            $response->getBody()->write(json_encode(['error' => 'Invalid API key'], JSON_THROW_ON_ERROR));
            return $response
                ->withStatus(StatusCodeInterface::STATUS_FORBIDDEN)
                ->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
