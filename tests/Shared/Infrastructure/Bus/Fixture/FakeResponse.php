<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Query\Response;

/**
 * In-test typed response returned by FakeQueryHandler, used to prove the query bus
 * returns the same typed Response instance the handler produced.
 */
final class FakeResponse implements Response
{
}
