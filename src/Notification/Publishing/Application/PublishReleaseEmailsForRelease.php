<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Application;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;

/**
 * The Publishing use-case: resolve the repository's subscribers and publish
 * one SendReleaseEmail integration message per recipient, as a single batch.
 *
 * No try/catch around publishAll(): a publish failure must propagate to
 * ScanReleasesHandler's per-repo catch so the scan marker is not advanced —
 * the release is retried next cycle (this is what keeps the flow outbox-free).
 * A partially delivered batch is safe: the consumer's ledger dedups when the
 * release is re-published.
 *
 * @psalm-api
 */
final readonly class PublishReleaseEmailsForRelease
{
    public function __construct(
        private SubscriberFinder $subscribers,
        private SendReleaseEmailFactoryInterface $messageFactory,
        private ReleaseNotificationPublisher $publisher
    ) {
    }

    public function __invoke(RepositoryName $repository, ReleaseSnapshot $release): void
    {
        $recipients = $this->subscribers->findSubscribersByRepository($repository);

        $messages = [];
        foreach ($recipients as $subscriber) {
            $messages[] = $this->messageFactory->fromRecipient(
                $subscriber->id,
                new EmailAddress($subscriber->email),
                $repository,
                $release
            );
        }

        if ($messages === []) {
            return;
        }

        $this->publisher->publishAll($messages);
    }
}
