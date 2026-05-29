<?php

declare(strict_types=1);

namespace Tests\Config\Factory;

use App\Config\Factory\SmtpConfigFactory;
use PHPUnit\Framework\TestCase;

final class SmtpConfigFactoryTest extends TestCase
{
    public function testBuildsSmtpConfigFromArray(): void
    {
        $config = (new SmtpConfigFactory())->fromArray([
            'host' => 'smtp.example.com',
            'port' => 587,
            'from' => 'noreply@example.com',
            'user' => 'mailer',
            'password' => 'secret',
            'encryption' => 'tls',
        ]);

        self::assertSame('smtp.example.com', $config->host);
        self::assertSame(587, $config->port);
        self::assertSame('noreply@example.com', $config->from);
        self::assertTrue($config->hasAuth());
        self::assertTrue($config->hasEncryption());
    }

    public function testReportsMissingAuthAndEncryption(): void
    {
        $config = (new SmtpConfigFactory())->fromArray([
            'host' => 'localhost',
            'port' => 1025,
            'from' => 'dev@local',
            'user' => '',
            'password' => '',
            'encryption' => '',
        ]);

        self::assertFalse($config->hasAuth());
        self::assertFalse($config->hasEncryption());
    }
}
