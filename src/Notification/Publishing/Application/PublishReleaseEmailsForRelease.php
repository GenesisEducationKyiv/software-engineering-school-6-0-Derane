<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Application;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Domain\SubscriberProvider;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * The Publishing use-case: resolve the repository's recipients and publish
 * one SendReleaseEmail integration message per recipient, as a single batch.
 *
 * Recipients are resolved through SubscriberProvider — this context's own port.
 * The bridge to the Subscription bounded context lives entirely in the ACL
 * adapter behind that port, so this use-case has no cross-context dependency.
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
        private SubscriberProvider $subscribers,
        private SendReleaseEmailFactoryInterface $messageFactory,
        private ReleaseNotificationPublisher $publisher
    ) {
    }

    public function __invoke(RepositoryName $repository, ReleaseSnapshot $release): void
    {
        $recipients = $this->subscribers->findRecipientsForRepository($repository);

        $messages = [];
        foreach ($recipients as $recipient) {
            $messages[] = $this->messageFactory->fromRecipient(
                $recipient->subscriptionId,
                $recipient->email,
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
