<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Http;

use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Application\Pagination\PaginationFactoryInterface;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryQuery;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\SubscriptionPageResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @psalm-api */
final readonly class SubscriptionController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
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

        $this->commandBus->dispatch(new SubscribeCommand($email, $repository));

        $result = $this->queryBus->ask(new FindSubscriptionByEmailAndRepositoryQuery($email, $repository));
        \assert($result instanceof SubscriptionResponse);

        return $this->json($response, $this->toArray($result), StatusCodeInterface::STATUS_CREATED);
    }

    /** @param array<string, string> $args */
    public function get(Request $_request, Response $response, array $args): Response
    {
        $result = $this->queryBus->ask(new FindSubscriptionByIdQuery((int) $args['id']));
        \assert($result instanceof SubscriptionResponse);

        return $this->json($response, $this->toArray($result));
    }

    public function list(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $email = isset($query['email']) ? trim((string) $query['email']) : null;
        $pagination = $this->paginationFactory->fromRequest(
            (int) ($query['limit'] ?? 0),
            (int) ($query['offset'] ?? 0)
        );

        $result = $this->queryBus->ask(new ListSubscriptionsQuery(
            $email !== '' ? $email : null,
            $pagination
        ));
        \assert($result instanceof SubscriptionPageResponse);

        return $this->json(
            $response,
            array_map(fn(SubscriptionResponse $s): array => $this->toArray($s), $result->items)
        );
    }

    /** @param array<string, string> $args */
    public function delete(Request $_request, Response $response, array $args): Response
    {
        $this->commandBus->dispatch(new UnsubscribeCommand((int) $args['id']));

        return $response->withStatus(StatusCodeInterface::STATUS_NO_CONTENT);
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    private function toArray(SubscriptionResponse $subscription): array
    {
        return [
            'id' => $subscription->id,
            'email' => $subscription->email,
            'repository' => $subscription->repository,
            'created_at' => $subscription->createdAt,
        ];
    }

    private function json(Response $response, mixed $data, int $status = StatusCodeInterface::STATUS_OK): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
