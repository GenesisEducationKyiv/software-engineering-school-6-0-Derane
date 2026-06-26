<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Clock;

use App\Shared\Infrastructure\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testReportsTheCurrentInstant(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $now->getTimestamp());
        $this->assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function testPinsUtcRegardlessOfThePhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');

        try {
            $this->assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
