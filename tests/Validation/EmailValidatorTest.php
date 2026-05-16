<?php

declare(strict_types=1);

namespace Tests\Validation;

use App\Exception\ValidationException;
use App\Validation\EmailValidator;
use PHPUnit\Framework\TestCase;

final class EmailValidatorTest extends TestCase
{
    public function testIsValidAcceptsWellFormedAddresses(): void
    {
        $validator = new EmailValidator();

        $this->assertTrue($validator->isValid('test@example.com'));
        $this->assertTrue($validator->isValid('a.b@c.d.com'));
    }

    public function testIsValidRejectsMalformedAddresses(): void
    {
        $validator = new EmailValidator();

        $this->assertFalse($validator->isValid('not-email'));
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid('@'));
    }

    public function testAssertValidThrowsForBadInput(): void
    {
        $this->expectException(ValidationException::class);

        (new EmailValidator())->assertValid('not-email');
    }
}
