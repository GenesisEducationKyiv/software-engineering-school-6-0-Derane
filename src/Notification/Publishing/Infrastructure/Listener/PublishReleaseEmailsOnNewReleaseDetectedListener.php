<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Releases\Sourcing\Domain\NewReleaseDetected;

/**
 * Thin adapter from the Releases-owned NewReleaseDetected event to the
 * Publishing use-case: maps the event's DetectedRelease into this context's
 * own ReleaseSnapshot (anti-corruption) and delegates. All publishing logic
 * lives in PublishReleaseEmailsForRelease. No null-tag guard needed —
 * DetectedRelease carries a non-nullable ReleaseTag by construction.
 *
 * @psalm-api
 */
final readonly class PublishReleaseEmailsOnNewReleaseDetectedListener
{
    public function __construct(private PublishReleaseEmailsForRelease $publishReleaseEmails)
    {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        $release = $event->detected->release;

        // Map once per dispatch — the same snapshot is shared across all recipients.
        ($this->publishReleaseEmails)($event->repository, new ReleaseSnapshot(
            $event->detected->tag,
            $release->name,
            $release->htmlUrl,
            $release->publishedAt,
            $release->body,
        ));
    }
}
