<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Query\Query;

/**
 * In-test query with no handler in the map, used to prove the bus throws
 * QueryNotRegistered naming this class.
 */
final class UnboundQuery implements Query
{
}
