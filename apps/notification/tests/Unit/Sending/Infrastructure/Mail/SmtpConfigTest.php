<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Mail;

use App\Sending\Infrastructure\Mail\SmtpConfig;
use PHPUnit\Framework\TestCase;

final class SmtpConfigTest extends TestCase
{
    public function testExposesConstructorValuesAsReadonlyProperties(): void
    {
        $config = new SmtpConfig(
            host: 'smtp.example.com',
            port: 587,
            from: 'noreply@example.com',
            user: 'user',
            password: 'pass',
            encryption: 'tls',
        );

        self::assertSame('smtp.example.com', $config->host);
        self::assertSame(587, $config->port);
        self::assertSame('noreply@example.com', $config->from);
        self::assertSame('user', $config->user);
        self::assertSame('pass', $config->password);
        self::assertSame('tls', $config->encryption);
    }

    public function testHasAuthIsTrueWhenUserIsNotEmpty(): void
    {
        $config = new SmtpConfig('host', 25, 'from@example.com', 'user', 'pass', '');

        self::assertTrue($config->hasAuth());
    }

    public function testHasAuthIsFalseWhenUserIsEmpty(): void
    {
        $config = new SmtpConfig('host', 25, 'from@example.com', '', '', '');

        self::assertFalse($config->hasAuth());
    }

    public function testHasEncryptionIsTrueWhenEncryptionIsNotEmpty(): void
    {
        $config = new SmtpConfig('host', 25, 'from@example.com', '', '', 'tls');

        self::assertTrue($config->hasEncryption());
    }

    public function testHasEncryptionIsFalseWhenEncryptionIsEmpty(): void
    {
        $config = new SmtpConfig('host', 25, 'from@example.com', '', '', '');

        self::assertFalse($config->hasEncryption());
    }
}
