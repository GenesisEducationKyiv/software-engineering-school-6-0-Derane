<?php

declare(strict_types=1);

namespace App\Notifier;

use App\Domain\Release;

final readonly class ReleaseEmailRenderer
{
    public function render(string $repository, Release $release): RenderedEmail
    {
        $tag = $release->tagName ?? '';

        return new RenderedEmail(
            "New Release: {$repository} {$tag}",
            $this->buildHtmlBody($repository, $release),
            $this->buildTextBody($repository, $release),
        );
    }

    private function buildHtmlBody(string $repository, Release $release): string
    {
        $escapedRepo = htmlspecialchars($repository);
        $escapedTag = htmlspecialchars($release->tagName ?? '');
        $escapedName = htmlspecialchars($release->name);
        $escapedUrl = htmlspecialchars($release->htmlUrl);
        $escapedBody = nl2br(htmlspecialchars($release->body));

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>New Release for {$escapedRepo}</h2>
            <p><strong>Version:</strong> {$escapedTag}</p>
            <p><strong>Name:</strong> {$escapedName}</p>
            <div style="margin: 16px 0; padding: 12px; background: #f6f8fa; border-radius: 6px;">
                {$escapedBody}
            </div>
            <p><a href="{$escapedUrl}" style="color: #0366d6;">View Release on GitHub</a></p>
            <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 24px 0;">
            <p style="color: #586069; font-size: 12px;">
                You received this email because you subscribed to release notifications for {$escapedRepo}.
            </p>
        </div>
        HTML;
    }

    private function buildTextBody(string $repository, Release $release): string
    {
        $tag = $release->tagName ?? '';

        return <<<TEXT
        New Release for {$repository}

        Version: {$tag}
        Name: {$release->name}

        {$release->body}

        View Release: {$release->htmlUrl}
        TEXT;
    }
}
