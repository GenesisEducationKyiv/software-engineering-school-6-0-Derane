<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\ReleaseEmail;
use App\Sending\Domain\RenderedEmail;

/**
 * Adapted from the monolith's `App\Scanning\Scanner\Infrastructure\Mail\ReleaseEmailRenderer`,
 * rebuilt around `ReleaseEmail`'s narrower wire-mapped shape.
 *
 * ## The no-`body` template-adaptation decision (Technical Decisions §3)
 *
 * The monolith's renderer prominently features `Release::$body` (the GitHub
 * release's free-text description/changelog) in both templates. `ReleaseEmail`
 * — D3's flattened wire-mapped VO — carries NO equivalent field: the wire
 * format (`SendReleaseEmailSerializer::toArray()`) never serializes
 * `release.body`/`description`/`notes` onto the integration message at all
 * (`schema, eventId, occurredAt, subscriptionId, email, repository,
 * release: {tagName, name, htmlUrl, publishedAt}`). This is not an oversight
 * D4 can "fix" — widening `ReleaseEmail` or the wire format itself would mean
 * touching C1's `SendReleaseEmailSerializer`/`SendReleaseEmail` (monolith files
 * this story cannot touch) and retroactively reshaping a byte-stable,
 * additive-only wire contract three stories after it shipped.
 *
 * **Decision: omit the body/description section from both templates entirely**
 * — no placeholder string ("(no description available)" or similar) either,
 * since that would invent copy not grounded in any data the message carries
 * and would read as a broken/incomplete email to a real subscriber. Both
 * bodies are rebuilt exclusively from the seven fields `ReleaseEmail` actually
 * has: repository, tagName, releaseName, releaseUrl, and (as a data-grounded,
 * human-meaningful addition) publishedAt.
 */
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
        $escapedPublishedAt = htmlspecialchars($email->publishedAt);

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>New Release for {$escapedRepo}</h2>
            <p><strong>Version:</strong> {$escapedTag}</p>
            <p><strong>Name:</strong> {$escapedName}</p>
            <p><strong>Published:</strong> {$escapedPublishedAt}</p>
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
        Published: {$email->publishedAt}

        View Release: {$email->releaseUrl}
        TEXT;
    }
}
