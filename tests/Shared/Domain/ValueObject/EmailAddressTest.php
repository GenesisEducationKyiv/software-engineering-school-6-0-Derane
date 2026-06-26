<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\EmailAddress;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validProvider(): iterable
    {
        yield 'plain' => ['test@example.com'];
        yield 'dotted' => ['a.b@c.d.com'];
        yield 'plus tag' => ['user+tag@ex.io'];
    }

    /**
     * @dataProvider validProvider
     */
    public function testConstructsAndExposesValue(string $input): void
    {
        $vo = new EmailAddress($input);

        $this->assertSame($input, $vo->value());
        $this->assertSame($input, (string) $vo);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'no domain' => ['not-email'];
        yield 'empty' => [''];
        yield 'only at' => ['@'];
        yield 'missing domain' => ['user@'];
        yield 'leading space' => [' x@y.z'];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testRejectsMalformedAddresses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EmailAddress($input);
    }

    public function testEquals(): void
    {
        $vo = new EmailAddress('test@example.com');

        $this->assertTrue($vo->equals(new EmailAddress('test@example.com')));
        $this->assertFalse($vo->equals(new EmailAddress('other@example.com')));
    }
}
