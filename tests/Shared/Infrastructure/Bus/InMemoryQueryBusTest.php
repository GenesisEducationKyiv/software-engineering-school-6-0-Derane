<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Query\QueryNotRegistered;
use App\Shared\Infrastructure\Bus\InMemoryQueryBus;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Infrastructure\Bus\Fixture\FakeQuery;
use Tests\Shared\Infrastructure\Bus\Fixture\FakeQueryHandler;
use Tests\Shared\Infrastructure\Bus\Fixture\FakeResponse;
use Tests\Shared\Infrastructure\Bus\Fixture\UnboundQuery;

final class InMemoryQueryBusTest extends TestCase
{
    public function testReturnsTheTypedResponseFromTheSingleBoundHandler(): void
    {
        $expected = new FakeResponse();
        $bus = new InMemoryQueryBus([FakeQuery::class => new FakeQueryHandler($expected)]);

        $result = $bus->ask(new FakeQuery());

        // The typed Response is returned (no mixed leak) and it is the exact
        // instance the handler produced.
        self::assertInstanceOf(FakeResponse::class, $result);
        self::assertSame($expected, $result);
    }

    public function testUnboundQueryThrowsQueryNotRegisteredNamingTheQuery(): void
    {
        $bus = new InMemoryQueryBus([]);

        $this->expectException(QueryNotRegistered::class);
        $this->expectExceptionMessage(UnboundQuery::class);

        $bus->ask(new UnboundQuery());
    }
}
