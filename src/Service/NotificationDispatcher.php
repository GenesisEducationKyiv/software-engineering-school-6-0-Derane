<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;
use App\Domain\SubscriberRef;
use App\Repository\NotificationLedgerInterface;
use App\Repository\SubscriberFinderInterface;

/** @psalm-api */
final class NotificationDispatcher
{
    public function __construct(
        private SubscriberFinderInterface $subscribers,
        private NotificationLedgerInterface $ledger,
        private NotifierInterface $notifier
    ) {
    }

    /**
     * Filters out subscribers already notified for this release, then delivers
     * the remaining notifications. Returns true when every attempted delivery
     * succeeded.
     */
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
