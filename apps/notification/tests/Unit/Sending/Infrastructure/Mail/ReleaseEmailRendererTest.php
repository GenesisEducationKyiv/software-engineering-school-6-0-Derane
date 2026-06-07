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
            subscriptionId: 42,
            recipientEmail: 'subscriber@example.com',
            repository: 'owner/repo',
            tagName: 'v1.2.3',
            releaseName: 'Shiny New Release',
            releaseUrl: 'https://github.com/owner/repo/releases/tag/v1.2.3',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );
    }

    public function testSubjectIncludesRepositoryAndTag(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertSame('New Release: owner/repo v1.2.3', $rendered->subject);
    }

    public function testHtmlBodyIncludesEveryReleaseEmailField(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertStringContainsString('owner/repo', $rendered->htmlBody);
        self::assertStringContainsString('v1.2.3', $rendered->htmlBody);
        self::assertStringContainsString('Shiny New Release', $rendered->htmlBody);
        self::assertStringContainsString('https://github.com/owner/repo/releases/tag/v1.2.3', $rendered->htmlBody);
        self::assertStringContainsString('2026-06-07T11:00:00+00:00', $rendered->htmlBody);
    }

    public function testTextBodyIncludesEveryReleaseEmailField(): void
    {
        $rendered = $this->renderer->render($this->email());

        self::assertStringContainsString('owner/repo', $rendered->textBody);
        self::assertStringContainsString('v1.2.3', $rendered->textBody);
        self::assertStringContainsString('Shiny New Release', $rendered->textBody);
        self::assertStringContainsString('https://github.com/owner/repo/releases/tag/v1.2.3', $rendered->textBody);
        self::assertStringContainsString('2026-06-07T11:00:00+00:00', $rendered->textBody);
    }

    /**
     * Regression guard for the §3 template-adaptation decision: ReleaseEmail
     * carries no body/description field (the wire format never serializes
     * release.body — confirmed against SendReleaseEmailSerializer::toArray()).
     * Both bodies must therefore contain NEITHER a real description (there is
     * none to render) NOR an invented placeholder string standing in for one
     * — the templates must be built exclusively from fields ReleaseEmail
     * actually carries (repository/tagName/releaseName/releaseUrl/publishedAt).
     */
    public function testNeitherBodyInventsADescriptionPlaceholder(): void
    {
        $rendered = $this->renderer->render($this->email());

        foreach (['no description', 'description not available', 'no release notes', 'N/A'] as $placeholder) {
            self::assertStringNotContainsStringIgnoringCase($placeholder, $rendered->htmlBody);
            self::assertStringNotContainsStringIgnoringCase($placeholder, $rendered->textBody);
        }
    }

    public function testEscapesHtmlSpecialCharactersInHtmlBody(): void
    {
        $email = new ReleaseEmail(
            subscriptionId: 1,
            recipientEmail: 'a@b.c',
            repository: '<script>alert(1)</script>',
            tagName: 'v1',
            releaseName: 'Name & "Quotes"',
            releaseUrl: 'https://example.com/?a=1&b=2',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );

        $rendered = $this->renderer->render($email);

        self::assertStringNotContainsString('<script>', $rendered->htmlBody);
        self::assertStringContainsString('&lt;script&gt;', $rendered->htmlBody);
    }
}
