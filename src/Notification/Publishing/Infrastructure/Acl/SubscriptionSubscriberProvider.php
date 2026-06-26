<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Acl;

use App\Notification\Publishing\Domain\Recipient;
use App\Notification\Publishing\Domain\SubscriberProvider;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;

/**
 * Anti-Corruption Layer between Notification\Publishing and the Subscription
 * bounded context. This is the *only* class in Publishing that imports
 * Subscription types: it calls the Subscription-owned SubscriberFinder port and
 * translates each SubscriberRef into this context's own Recipient. Keeping the
 * translation here means the Publishing Application/Domain layers stay free of
 * any Subscription dependency — if Subscription's model changes, only this
 * adapter moves.
 *
 * Permanent boundary, not a Strangler bridge: Subscription and Publishing both
 * stay in the monolith (unlike Notification\Sending, which is extracted to its
 * own service). Recipient emails are validated at subscription time, so the
 * EmailAddress construction below is not expected to fail; a validation error
 * would signal upstream data corruption and is deliberately left to propagate
 * to the scan orchestrator (no try/catch), matching the outbox-free retry flow.
 *
 * @psalm-api
 */
final readonly class SubscriptionSubscriberProvider implements SubscriberProvider
{
    public function __construct(private SubscriberFinder $subscribers)
    {
    }

    #[\Override]
    public function findRecipientsForRepository(RepositoryName $repository): array
    {
        $recipients = [];
        foreach ($this->subscribers->findSubscribersByRepository($repository) as $subscriber) {
            $recipients[] = new Recipient($subscriber->id, new EmailAddress($subscriber->email));
        }

        return $recipients;
    }
}
