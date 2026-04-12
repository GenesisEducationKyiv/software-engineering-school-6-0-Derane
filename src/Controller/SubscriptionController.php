<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Service\SubscriptionServiceInterface;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SubscriptionController
{
    public function __construct(private SubscriptionServiceInterface $service)
    {
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
        return $this->json($response, $subscription, StatusCodeInterface::STATUS_CREATED);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->service->getSubscription((int) $args['id']);
        return $this->json($response, $subscription);
    }

    public function list(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $email = isset($query['email']) ? trim((string) $query['email']) : null;
        $limit = max(1, min(100, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $subscriptions = $this->service->listSubscriptions($email !== '' ? $email : null, $limit, $offset);
        return $this->json($response, $subscriptions);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->service->unsubscribe((int) $args['id']);
        return $response->withStatus(StatusCodeInterface::STATUS_NO_CONTENT);
    }

    private function json(Response $response, mixed $data, int $status = StatusCodeInterface::STATUS_OK): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
