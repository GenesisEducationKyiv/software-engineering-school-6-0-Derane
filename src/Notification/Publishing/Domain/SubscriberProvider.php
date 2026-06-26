<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Publishing-owned port for resolving a repository's recipients. The use-case
 * depends on this — never on the Subscription bounded context directly. The
 * one adapter that bridges to Subscription (the Anti-Corruption Layer) lives in
 * Infrastructure\Acl and is the single place that imports Subscription types.
 *
 * @psalm-api
 */
interface SubscriberProvider
{
    /** @return list<Recipient> */
    public function findRecipientsForRepository(RepositoryName $repository): array;
}
