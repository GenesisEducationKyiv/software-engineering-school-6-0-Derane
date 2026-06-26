<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\ReleaseTag;
use PHPUnit\Framework\TestCase;

final class ReleaseTagTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validProvider(): iterable
    {
        yield 'semver v' => ['v1.2.3'];
        yield 'semver' => ['1.0.0'];
        yield 'named' => ['release-2024'];
        yield 'short' => ['v0'];
    }

    /**
     * @dataProvider validProvider
     */
    public function testConstructsAndExposesValue(string $input): void
    {
        $vo = new ReleaseTag($input);

        $this->assertSame($input, $vo->value());
        $this->assertSame($input, (string) $vo);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testRejectsEmptyOrWhitespace(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReleaseTag($input);
    }

    public function testEquals(): void
    {
        $vo = new ReleaseTag('v1.2.3');

        $this->assertTrue($vo->equals(new ReleaseTag('v1.2.3')));
        $this->assertFalse($vo->equals(new ReleaseTag('v2.0.0')));
    }
}
