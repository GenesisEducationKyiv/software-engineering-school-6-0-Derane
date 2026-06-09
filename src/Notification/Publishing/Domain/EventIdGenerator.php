<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

/**
 * Port for minting SendReleaseEmail eventIds (correlation/trace identifiers),
 * so the factory's output is deterministic in tests.
 *
 * @psalm-api
 */
interface EventIdGenerator
{
    public function generate(): string;
}
