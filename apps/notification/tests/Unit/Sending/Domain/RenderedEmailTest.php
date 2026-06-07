<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\RenderedEmail;
use PHPUnit\Framework\TestCase;

final class RenderedEmailTest extends TestCase
{
    public function testConstructsAndExposesAllProperties(): void
    {
        $rendered = new RenderedEmail(
            subject: 'New Release: owner/repo v1.2.3',
            htmlBody: '<p>html</p>',
            textBody: 'text',
        );

        self::assertSame('New Release: owner/repo v1.2.3', $rendered->subject);
        self::assertSame('<p>html</p>', $rendered->htmlBody);
        self::assertSame('text', $rendered->textBody);
    }
}
