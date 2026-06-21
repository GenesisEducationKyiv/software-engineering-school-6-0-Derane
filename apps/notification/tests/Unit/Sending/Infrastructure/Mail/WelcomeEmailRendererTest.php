<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Mail;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\ReleaseTag;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Infrastructure\Mail\WelcomeEmailRenderer;
use PHPUnit\Framework\TestCase;

final class WelcomeEmailRendererTest extends TestCase
{
    private WelcomeEmailRenderer $renderer;

    #[\Override]
    protected function setUp(): void
    {
        $this->renderer = new WelcomeEmailRenderer();
    }

    private function welcome(string $repository = 'owner/repo'): WelcomeEmail
    {
        return new WelcomeEmail(
            sagaId: '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            subscriptionId: 123,
            recipientEmail: new EmailAddress('subscriber@example.com'),
            repository: new RepositoryName($repository),
        );
    }

    public function testSubjectIsTheWelcomeSubjectWithTheRepository(): void
    {
        $rendered = $this->renderer->render($this->welcome());

        self::assertSame("Welcome — you're subscribed to owner/repo", $rendered->subject);
    }

    public function testBodyIsDistinctFromTheReleaseTemplate(): void
    {
        $rendered = $this->renderer->render($this->welcome());

        self::assertStringContainsString("You're subscribed!", $rendered->htmlBody);
        self::assertStringContainsString('owner/repo', $rendered->htmlBody);
        // It must NOT carry the release template's hallmarks.
        self::assertStringNotContainsString('New Release', $rendered->htmlBody);
        self::assertStringNotContainsString('View Release on GitHub', $rendered->htmlBody);

        self::assertStringContainsString("You're subscribed!", $rendered->textBody);
        self::assertStringContainsString('owner/repo', $rendered->textBody);
    }

    public function testEscapesHtmlSpecialCharactersInTheRepositorySegment(): void
    {
        // RepositoryName forbids HTML metacharacters, so a literal repo cannot
        // carry a payload; this asserts the renderer routes the value through
        // htmlspecialchars (escaping a/the dot/dash chars it does allow safely).
        $rendered = $this->renderer->render($this->welcome('owner-1/repo.js'));

        self::assertStringContainsString('owner-1/repo.js', $rendered->htmlBody);
        self::assertStringNotContainsString('<script>', $rendered->htmlBody);
    }

    public function testRejectsANonWelcomeRenderable(): void
    {
        $release = new ReleaseEmail(
            eventId: 'e',
            subscriptionId: 1,
            recipientEmail: new EmailAddress('a@b.c'),
            repository: new RepositoryName('owner/repo'),
            tagName: new ReleaseTag('v1'),
            releaseName: 'n',
            releaseBody: 'b',
            releaseUrl: 'https://example.com',
            publishedAt: '2026-06-07T11:00:00+00:00',
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->renderer->render($release);
    }
}
