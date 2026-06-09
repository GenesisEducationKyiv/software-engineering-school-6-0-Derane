<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Infrastructure\Factory\UuidV4EventIdGenerator;
use PHPUnit\Framework\TestCase;

final class UuidV4EventIdGeneratorTest extends TestCase
{
    private const UUID_V4_PATTERN
        = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testGeneratesRfc4122Version4FormattedIds(): void
    {
        $generator = new UuidV4EventIdGenerator();

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $generator->generate());
    }

    public function testGeneratesAFreshIdPerCall(): void
    {
        $generator = new UuidV4EventIdGenerator();

        $this->assertNotSame($generator->generate(), $generator->generate());
    }
}
