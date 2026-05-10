<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Factory\PaginationFactoryInterface;
use App\Exception\ValidationException;
use App\Service\SubscriptionServiceInterface;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @psalm-api */
final class SubscriptionController
{
    public function __construct(
        private SubscriptionServiceInterface $service,
        private PaginationFactoryInterface $paginationFactory
    ) {
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('request body must be a JSON object');
        }

        $email = trim((string) ($body['email'] ?? ''));
        $repository = trim((string) ($body['repository'] ?? ''));

        if ($email === '' || $repository === '') {
            throw new ValidationException('email and repository are required');
        }

        $subscription = $this->service->subscribe($email, $repository);
        return $this->json($response, $subscription->toArray(), StatusCodeInterface::STATUS_CREATED);
    }

    /** @param array<string, string> $args */
    public function get(Request $_request, Response $response, array $args): Response
    {
        $subscription = $this->service->getSubscription((int) $args['id']);
        return $this->json($response, $subscription->toArray());
    }

    public function list(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $email = isset($query['email']) ? trim((string) $query['email']) : null;
        $pagination = $this->paginationFactory->fromRequest(
            (int) ($query['limit'] ?? 0),
            (int) ($query['offset'] ?? 0)
        );

        $page = $this->service->listSubscriptions(
            $email !== '' ? $email : null,
            $pagination
        );

        return $this->json(
            $response,
            array_map(static fn($s) => $s->toArray(), $page->items)
        );
    }

    /** @param array<string, string> $args */
    public function delete(Request $_request, Response $response, array $args): Response
    {
        $this->service->unsubscribe((int) $args['id']);
        return $response->withStatus(StatusCodeInterface::STATUS_NO_CONTENT);
    }

    private function json(Response $response, mixed $data, int $status = StatusCodeInterface::STATUS_OK): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
