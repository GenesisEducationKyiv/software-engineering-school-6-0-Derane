<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application;

use App\Releases\Sourcing\Domain\Release;

/**
 * Orphaned by the E1 cutover: its sole caller, the in-process
 * `NotificationDispatcher`, was deleted once `ScanReleasesHandler` started
 * gating `markReleaseSeen()` on the Rabbit publish instead of legacy SMTP
 * delivery (AC4 — "no silent dual-send"). `NotifierService`/`SmtpMailer`/
 * `NotificationLedger` form one atomic dead cluster now reachable only
 * through this port; epics.md scopes their joint deletion to E4 —
 * "Decommission monolith notification stack", deliberately gated on E1–E3
 * proving the cutover first. `@psalm-api` documents that "unused" here is
 * intentional-and-temporary, not an oversight (mirrors
 * `RabbitConnection`/`ReleaseNotificationPublisher`).
 *
 * @psalm-api
 */
interface NotifierInterface
{
    public function notifyReleaseAvailable(string $email, string $repository, Release $release): bool;
}
