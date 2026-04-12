<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\RateLimitException;
use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            return $this->jsonError($e->getMessage(), StatusCodeInterface::STATUS_BAD_REQUEST);
        } catch (RepositoryNotFoundException | SubscriptionNotFoundException $e) {
            return $this->jsonError($e->getMessage(), StatusCodeInterface::STATUS_NOT_FOUND);
        } catch (RateLimitException $e) {
            return $this->jsonError(
                'GitHub API rate limit exceeded. Please try again later.',
                StatusCodeInterface::STATUS_TOO_MANY_REQUESTS
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception: ' . $e->getMessage(), [
                'exception' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->jsonError('Internal server error', StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
