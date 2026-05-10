<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;
use PHPUnit\Framework\TestCase;

final class SubscriptionPageTest extends TestCase
{
    public function testHasNextPageWhenMoreRowsRemain(): void
    {
        $page = new SubscriptionPage(
            [new Subscription(1, 'a@b.com', 'x/y', '2024-01-01T00:00:00Z')],
            Pagination::fromRequest(1, 0),
            5
        );

        $this->assertTrue($page->hasNextPage());
    }

    public function testNoNextPageWhenLastWindowFetched(): void
    {
        $page = new SubscriptionPage(
            [new Subscription(1, 'a@b.com', 'x/y', '2024-01-01T00:00:00Z')],
            Pagination::fromRequest(1, 4),
            5
        );

        $this->assertFalse($page->hasNextPage());
    }

    public function testNoNextPageWhenEmpty(): void
    {
        $page = new SubscriptionPage([], Pagination::fromRequest(10, 0), 0);

        $this->assertFalse($page->hasNextPage());
    }
}
