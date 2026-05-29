<?php

declare(strict_types=1);

namespace Tests\Config\Factory;

use App\Config\Factory\PaginationFactory;
use PHPUnit\Framework\TestCase;

final class PaginationFactoryTest extends TestCase
{
    public function testDefaultsLimitWhenZeroOrNegative(): void
    {
        $factory = new PaginationFactory();

        self::assertSame(100, $factory->fromRequest(0, 0)->limit);
        self::assertSame(100, $factory->fromRequest(-5, 0)->limit);
    }

    public function testClampsLimitToMaximum(): void
    {
        $factory = new PaginationFactory();

        self::assertSame(100, $factory->fromRequest(500, 0)->limit);
    }

    public function testKeepsValidLimitAsIs(): void
    {
        $factory = new PaginationFactory();

        self::assertSame(25, $factory->fromRequest(25, 50)->limit);
        self::assertSame(50, $factory->fromRequest(25, 50)->offset);
    }

    public function testFloorsNegativeOffsetToZero(): void
    {
        $factory = new PaginationFactory();

        self::assertSame(0, $factory->fromRequest(10, -3)->offset);
    }
}
