<?php

declare(strict_types=1);

namespace Tests\Validation;

use App\Exception\ValidationException;
use App\Validation\RepositoryNameValidator;
use PHPUnit\Framework\TestCase;

final class RepositoryNameValidatorTest extends TestCase
{
    public function testIsValidAcceptsOwnerRepoForms(): void
    {
        $validator = new RepositoryNameValidator();

        $this->assertTrue($validator->isValid('golang/go'));
        $this->assertTrue($validator->isValid('php/php-src'));
        $this->assertTrue($validator->isValid('a/b'));
    }

    public function testIsValidRejectsBadShapes(): void
    {
        $validator = new RepositoryNameValidator();

        $this->assertFalse($validator->isValid('invalid'));
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid('a/b/c'));
        $this->assertFalse($validator->isValid('/'));
        $this->assertFalse($validator->isValid('a/'));
        $this->assertFalse($validator->isValid('/b'));
    }

    public function testAssertValidThrowsForBadInput(): void
    {
        $this->expectException(ValidationException::class);

        (new RepositoryNameValidator())->assertValid('invalid');
    }
}
