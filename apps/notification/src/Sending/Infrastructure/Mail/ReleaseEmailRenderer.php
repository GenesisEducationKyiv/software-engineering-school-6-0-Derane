<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\RenderedEmail;

final readonly class ReleaseEmailRenderer implements EmailRenderer
{
    #[\Override]
    public function render(ReleaseEmail $email): RenderedEmail
    {
        return new RenderedEmail(
            "New Release: {$email->repository} {$email->tagName}",
            $this->buildHtmlBody($email),
            $this->buildTextBody($email),
        );
    }

    private function buildHtmlBody(ReleaseEmail $email): string
    {
        $escapedRepo = htmlspecialchars($email->repository);
        $escapedTag = htmlspecialchars($email->tagName);
        $escapedName = htmlspecialchars($email->releaseName);
        $escapedUrl = htmlspecialchars($email->releaseUrl);
        $escapedBody = nl2br(htmlspecialchars($email->releaseBody));

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

    private function buildTextBody(ReleaseEmail $email): string
    {
        return <<<TEXT
        New Release for {$email->repository}

        Version: {$email->tagName}
        Name: {$email->releaseName}

        {$email->releaseBody}

        View Release: {$email->releaseUrl}
        TEXT;
    }
}
