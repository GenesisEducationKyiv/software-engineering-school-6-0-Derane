<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ExceptionStatusMap;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        private ExceptionStatusMap $statusMap
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $status = $this->statusMap->toHttpStatus($e);

            if ($status === StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR) {
                $this->logger->error('Unhandled exception: ' . $e->getMessage(), [
                    'exception' => $e::class,
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            return $this->jsonError($this->statusMap->toClientMessage($e), $status);
        }
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
