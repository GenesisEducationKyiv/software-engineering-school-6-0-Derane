<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\EmailRenderer;
use PHPUnit\Framework\TestCase;

final class EmailRendererTest extends TestCase
{
    public function testInterfaceIsImplementable(): void
    {
        $renderer = $this->createMock(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
    }
}
