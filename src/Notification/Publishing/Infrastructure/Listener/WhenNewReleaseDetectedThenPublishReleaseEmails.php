<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Domain\NewReleaseDetected;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactoryInterface;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;

/**
 * No try/catch around publish(): a publish failure must propagate out of
 * this listener so ScanReleasesHandler's per-repo catch sees it and does
 * not advance the scan marker — the release is retried next cycle.
 *
 * @psalm-api
 */
final readonly class WhenNewReleaseDetectedThenPublishReleaseEmails
{
    public function __construct(
        private SubscriberFinder $subscribers,
        private SendReleaseEmailFactoryInterface $messageFactory,
        private ReleaseNotificationPublisher $publisher
    ) {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        $tag = $event->release->tagName;
        if ($tag === null) {
            // ReleaseTag throws on null/empty, so a tagless release must be
            // skipped here rather than crash the whole scan loop.
            return;
        }

        $recipients = $this->subscribers->findSubscribersByRepository($event->repository->value());

        // Map once per dispatch — the same snapshot is shared across all recipients.
        $releaseSnapshot = new ReleaseSnapshot(
            new ReleaseTag($tag),
            $event->release->name,
            $event->release->htmlUrl,
            $event->release->publishedAt,
            $event->release->body,
        );

        foreach ($recipients as $subscriber) {
            $message = $this->messageFactory->fromRecipient(
                $subscriber->id,
                new EmailAddress($subscriber->email),
                $event->repository,
                $releaseSnapshot
            );

            $this->publisher->publish($message);
        }
    }
}
