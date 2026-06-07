<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testInterfaceIsImplementable(): void
    {
        $mailer = $this->createMock(Mailer::class);
        self::assertInstanceOf(Mailer::class, $mailer);
    }
}
