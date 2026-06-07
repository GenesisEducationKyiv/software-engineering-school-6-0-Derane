<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Http;

use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        private ExceptionStatusMap $statusMap,
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
                ]);
            }

            $response = $this->responseFactory->createResponse($status);
            $response->getBody()->write(json_encode(
                ['error' => $this->statusMap->toClientMessage($e)],
                JSON_THROW_ON_ERROR
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }
    }
}
