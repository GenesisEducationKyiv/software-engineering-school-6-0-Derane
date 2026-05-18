<?php

declare(strict_types=1);

namespace Tests\Notifier;

use App\Domain\Release;
use App\Notifier\ReleaseEmailRenderer;
use PHPUnit\Framework\TestCase;

final class ReleaseEmailRendererTest extends TestCase
{
    public function testEscapesUserControlledHtmlFields(): void
    {
        $renderer = new ReleaseEmailRenderer();
        $release = new Release(
            '<v1>',
            '<strong>Release</strong>',
            'https://github.com/acme/tool/releases/tag/v1',
            '2026-05-11',
            "Line 1\n<script>alert('xss')</script>"
        );

        $email = $renderer->render('acme/<tool>', $release);

        self::assertStringNotContainsString('<script>', $email->htmlBody);
        self::assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $email->htmlBody);
        self::assertStringContainsString('acme/&lt;tool&gt;', $email->htmlBody);
        self::assertStringContainsString('&lt;strong&gt;Release&lt;/strong&gt;', $email->htmlBody);
        self::assertStringContainsString('&lt;v1&gt;', $email->htmlBody);
    }
}
