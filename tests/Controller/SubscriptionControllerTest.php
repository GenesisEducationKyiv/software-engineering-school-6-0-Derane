<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Config\Pagination;
use App\Controller\SubscriptionController;
use App\Domain\SubscriptionPage;
use App\Exception\ValidationException;
use App\Service\SubscriptionServiceInterface;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class SubscriptionControllerTest extends TestCase
{
    public function testCreateRejectsNonArrayParsedBody(): void
    {
        $service = $this->createMock(SubscriptionServiceInterface::class);
        $service->expects($this->never())->method('subscribe');

        $controller = new SubscriptionController($service);
        $request = (new RequestFactory())
            ->createRequest('POST', '/api/subscriptions')
            ->withParsedBody((object) ['email' => 'test@example.com']);
        $response = (new ResponseFactory())->createResponse();

        $this->expectException(ValidationException::class);

        $controller->create($request, $response);
    }

    public function testListPassesPaginationArgumentsToService(): void
    {
        $service = $this->createMock(SubscriptionServiceInterface::class);
        $service->expects($this->once())
            ->method('listSubscriptions')
            ->with(
                'test@example.com',
                $this->callback(static fn(Pagination $p): bool => $p->limit === 25 && $p->offset === 50)
            )
            ->willReturn(new SubscriptionPage([], Pagination::fromRequest(25, 50), 0));

        $controller = new SubscriptionController($service);
        $request = (new RequestFactory())->createRequest(
            'GET',
            '/api/subscriptions?email=test@example.com&limit=25&offset=50'
        );
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->list($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
    }
}
