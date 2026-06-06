<?php

declare(strict_types=1);

namespace App\Service;

use App\Releases\Sourcing\Domain\Release;
use App\Repository\NotificationLedgerInterface;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriberRef;

/** @psalm-api */
final readonly class NotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private SubscriberFinder $subscribers,
        private NotificationLedgerInterface $ledger,
        private NotifierInterface $notifier
    ) {
    }

    #[\Override]
    public function dispatch(string $repoName, Release $release): bool
    {
        $tag = $release->tagName;
        if ($tag === null) {
            return true;
        }

        $pending = $this->subscribers
            ->findSubscribersByRepository($repoName)
            ->withoutAlreadyNotified(
                fn(SubscriberRef $s): bool => $this->ledger->hasSuccessfulNotification($s->id, $repoName, $tag)
            );

        $allDelivered = true;
        foreach ($pending as $subscriber) {
            $sent = $this->notifier->notifyReleaseAvailable($subscriber->email, $repoName, $release);
            $this->ledger->recordResult(
                $subscriber->id,
                $repoName,
                $tag,
                $sent,
                $sent ? null : 'Failed to send notification'
            );

            if (!$sent) {
                $allDelivered = false;
            }
        }

        return $allDelivered;
    }
}
