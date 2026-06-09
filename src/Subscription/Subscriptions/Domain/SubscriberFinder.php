<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Cross-context Domain port: resolves the subscribers of a repository for the
 * notification flow. A legitimate Domain port edge consumed by
 * Notification\Publishing's PublishReleaseEmailsForRelease use-case.
 *
 * @psalm-api
 */
interface SubscriberFinder
{
    public function findSubscribersByRepository(RepositoryName $repository): SubscriberCollection;
}
