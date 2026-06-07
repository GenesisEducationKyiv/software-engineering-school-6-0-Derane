<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Infrastructure\Http;

use App\Shared\Application\Pagination\PaginationFactory;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\ValueObject\Pagination;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryQuery;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\SubscriptionPageResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use App\Subscription\Subscriptions\Infrastructure\Http\SubscriptionController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class SubscriptionControllerTest extends TestCase
{
    private CommandBus&MockObject $commandBus;
    private QueryBus&MockObject $queryBus;
    private SubscriptionController $controller;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBus::class);
        $this->queryBus = $this->createMock(QueryBus::class);
        $this->controller = new SubscriptionController(
            $this->commandBus,
            $this->queryBus,
            new PaginationFactory()
        );
    }

    public function testCreateRejectsNonArrayParsedBody(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');

        $request = (new RequestFactory())
            ->createRequest('POST', '/api/subscriptions')
            ->withParsedBody((object) ['email' => 'test@example.com']);
        $response = (new ResponseFactory())->createResponse();

        $this->expectException(ValidationException::class);

        $this->controller->create($request, $response);
    }

    public function testCreateRejectsMissingFields(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');

        $request = (new RequestFactory())
            ->createRequest('POST', '/api/subscriptions')
            ->withParsedBody(['email' => '', 'repository' => '']);
        $response = (new ResponseFactory())->createResponse();

        $this->expectException(ValidationException::class);

        $this->controller->create($request, $response);
    }

    public function testCreateDispatchesCommandThenReadsBackAndReturns201(): void
    {
        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn(Command $c): bool =>
                $c instanceof SubscribeCommand
                && $c->email === 'a@b.com'
                && $c->repository === 'golang/go'));

        $this->queryBus->expects($this->once())
            ->method('ask')
            ->with($this->callback(static fn(Query $q): bool =>
                $q instanceof FindSubscriptionByEmailAndRepositoryQuery
                && $q->email === 'a@b.com'
                && $q->repository === 'golang/go'))
            ->willReturn(new SubscriptionResponse(9, 'a@b.com', 'golang/go', '2026-04-12T00:00:00Z'));

        $request = (new RequestFactory())
            ->createRequest('POST', '/api/subscriptions')
            ->withParsedBody(['email' => 'a@b.com', 'repository' => 'golang/go']);
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->create($request, $response);

        $this->assertSame(201, $result->getStatusCode());
        $this->assertSame(
            ['id' => 9, 'email' => 'a@b.com', 'repository' => 'golang/go', 'created_at' => '2026-04-12T00:00:00Z'],
            json_decode((string) $result->getBody(), true)
        );
    }

    public function testGetAsksByIdAndReturns200(): void
    {
        $this->queryBus->expects($this->once())
            ->method('ask')
            ->with($this->callback(static fn(Query $q): bool =>
                $q instanceof FindSubscriptionByIdQuery && $q->id === 3))
            ->willReturn(new SubscriptionResponse(3, 'a@b.com', 'golang/go', '2026-04-12T00:00:00Z'));

        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions/3');
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->get($request, $response, ['id' => '3']);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(
            ['id' => 3, 'email' => 'a@b.com', 'repository' => 'golang/go', 'created_at' => '2026-04-12T00:00:00Z'],
            json_decode((string) $result->getBody(), true)
        );
    }

    public function testListPassesClampedPaginationToQuery(): void
    {
        $this->queryBus->expects($this->once())
            ->method('ask')
            ->with($this->callback(static fn(Query $q): bool =>
                $q instanceof ListSubscriptionsQuery
                && $q->email === 'test@example.com'
                && $q->pagination instanceof Pagination
                && $q->pagination->limit === 25
                && $q->pagination->offset === 50))
            ->willReturn(new SubscriptionPageResponse([]));

        $request = (new RequestFactory())->createRequest(
            'GET',
            '/api/subscriptions?email=test@example.com&limit=25&offset=50'
        );
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->list($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame([], json_decode((string) $result->getBody(), true));
    }

    public function testListReturnsArrayOfFrozenShape(): void
    {
        $this->queryBus->method('ask')->willReturn(new SubscriptionPageResponse([
            new SubscriptionResponse(1, 'a@b.com', 'golang/go', '2026-04-12T00:00:00Z'),
        ]));

        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions');
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->list($request, $response);

        $this->assertSame(
            [['id' => 1, 'email' => 'a@b.com', 'repository' => 'golang/go', 'created_at' => '2026-04-12T00:00:00Z']],
            json_decode((string) $result->getBody(), true)
        );
    }

    public function testDeleteDispatchesUnsubscribeAndReturns204(): void
    {
        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn(Command $c): bool =>
                $c instanceof UnsubscribeCommand && $c->id === 5));

        $request = (new RequestFactory())->createRequest('DELETE', '/api/subscriptions/5');
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->delete($request, $response, ['id' => '5']);

        $this->assertSame(204, $result->getStatusCode());
    }
}
