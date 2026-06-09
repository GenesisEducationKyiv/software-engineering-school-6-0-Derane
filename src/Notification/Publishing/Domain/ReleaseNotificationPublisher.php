<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

/**
 * Publishes the whole batch for one release with a single broker
 * confirm-wait, instead of paying one confirm round trip per recipient.
 * Throws on any failure; a partially delivered batch is safe because the
 * consumer's ledger dedups when the release is re-published next scan cycle.
 *
 * @psalm-api
 */
interface ReleaseNotificationPublisher
{
    /** @param list<SendReleaseEmail> $messages */
    public function publishAll(array $messages): void;
}
