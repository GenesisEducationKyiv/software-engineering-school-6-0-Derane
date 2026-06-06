<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Query\Query;

/**
 * In-test query that the bus is expected to route to FakeQueryHandler.
 */
final class FakeQuery implements Query
{
}
