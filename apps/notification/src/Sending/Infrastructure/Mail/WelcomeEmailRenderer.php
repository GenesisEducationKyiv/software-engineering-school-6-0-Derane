<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\RenderableEmail;
use App\Sending\Domain\RenderedEmail;
use App\Sending\Domain\WelcomeEmail;

/**
 * Renders the welcome email from a template distinct from {@see ReleaseEmailRenderer}.
 * Implements the shared {@see EmailRenderer} port (narrowing to {@see WelcomeEmail});
 * every interpolated value is htmlspecialchars-escaped in the HTML body.
 */
final readonly class WelcomeEmailRenderer implements EmailRenderer
{
    #[\Override]
    public function render(RenderableEmail $email): RenderedEmail
    {
        if (!$email instanceof WelcomeEmail) {
            throw new \InvalidArgumentException(
                'WelcomeEmailRenderer can only render a WelcomeEmail, got ' . $email::class,
            );
        }

        $repository = $email->repository->value();

        return new RenderedEmail(
            "Welcome — you're subscribed to {$repository}",
            $this->buildHtmlBody($repository),
            $this->buildTextBody($repository),
        );
    }

    private function buildHtmlBody(string $repository): string
    {
        $escapedRepo = htmlspecialchars($repository);

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>You're subscribed!</h2>
            <p>Thanks for subscribing to release notifications for <strong>{$escapedRepo}</strong>.</p>
            <p>We'll email you whenever a new release is published.</p>
            <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 24px 0;">
            <p style="color: #586069; font-size: 12px;">
                You received this email because you subscribed to release notifications for {$escapedRepo}.
            </p>
        </div>
        HTML;
    }

    private function buildTextBody(string $repository): string
    {
        return <<<TEXT
        You're subscribed!

        Thanks for subscribing to release notifications for {$repository}.
        We'll email you whenever a new release is published.
        TEXT;
    }
}
