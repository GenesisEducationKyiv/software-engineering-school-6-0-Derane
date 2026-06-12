<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Mail;

use App\Sending\Domain\ReleaseEmail;
use App\Sending\Infrastructure\Mail\ReleaseEmailRenderer;
use PHPUnit\Framework\TestCase;

final class ReleaseEmailRendererTest extends TestCase
{
    private ReleaseEmailRenderer $renderer;

    #[\Override]
    protected function setUp(): void
    {
        $this->renderer = new ReleaseEmailRenderer();
    }

    private function email(): ReleaseEmail
    {
        return new ReleaseEmail(
            eventId: '11111111-1111-4111-8111-111111111111',
            subscriptionId: 42,
            recipientEmail: 'subscriber@example.com',
            repository: 'owner/repo',
            tagName: 'v1.2.3',
            releaseName: 'Shiny New Release',
            releaseBody: 'This release fixes several bugs and adds new features.',
            releaseUrl: 'https://github.com/owner/repo/releases/tag/v1.2.3',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );
    }

    public function testSubjectIncludesRepositoryAndTag(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertSame('New Release: owner/repo v1.2.3', $rendered->subject);
    }

    public function testHtmlBodyPreservesFrozenReleaseTemplate(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertStringContainsString('owner/repo', $rendered->htmlBody);
        self::assertStringContainsString('v1.2.3', $rendered->htmlBody);
        self::assertStringContainsString('Shiny New Release', $rendered->htmlBody);
        self::assertStringContainsString('This release fixes several bugs and adds new features.', $rendered->htmlBody);
        self::assertStringContainsString('https://github.com/owner/repo/releases/tag/v1.2.3', $rendered->htmlBody);
        self::assertStringContainsString(
            '<div style="margin: 16px 0; padding: 12px; background: #f6f8fa; border-radius: 6px;">',
            $rendered->htmlBody,
        );
        self::assertStringNotContainsString('Published:', $rendered->htmlBody);
        self::assertStringNotContainsString('2026-06-07T11:00:00+00:00', $rendered->htmlBody);
    }

    public function testTextBodyPreservesFrozenReleaseTemplate(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertStringContainsString('owner/repo', $rendered->textBody);
        self::assertStringContainsString('v1.2.3', $rendered->textBody);
        self::assertStringContainsString('Shiny New Release', $rendered->textBody);
        self::assertStringContainsString('This release fixes several bugs and adds new features.', $rendered->textBody);
        self::assertStringContainsString('https://github.com/owner/repo/releases/tag/v1.2.3', $rendered->textBody);
        self::assertStringNotContainsString('Published:', $rendered->textBody);
        self::assertStringNotContainsString('2026-06-07T11:00:00+00:00', $rendered->textBody);
    }

    public function testEscapesHtmlSpecialCharactersInHtmlBody(): void
    {
        $email = new ReleaseEmail(
            eventId: '11111111-1111-4111-8111-111111111111',
            subscriptionId: 1,
            recipientEmail: 'a@b.c',
            repository: '<script>alert(1)</script>',
            tagName: 'v1',
            releaseName: 'Name & "Quotes"',
            releaseBody: '<b>bold</b> & notes',
            releaseUrl: 'https://example.com/?a=1&b=2',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );

        $rendered = $this->renderer->render($email);

        self::assertStringNotContainsString('<script>', $rendered->htmlBody);
        self::assertStringContainsString('&lt;script&gt;', $rendered->htmlBody);
        self::assertStringNotContainsString('<b>bold</b>', $rendered->htmlBody);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $rendered->htmlBody);
    }
}
