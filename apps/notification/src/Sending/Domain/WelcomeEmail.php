<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * VO snapshot of a `SendWelcomeEmail/v1` message. References ONLY local
 * `Sending\Domain` types (local {@see EmailAddress}/{@see RepositoryName}, never
 * the Shared VOs) so `Notification.Domain` keeps its empty deptrac edge set.
 *
 * `sagaId` is carried verbatim as a string for trace continuity — the welcome
 * outcome reply echoes it back to the monolith orchestrator; the Sending domain
 * never interprets it, so no SagaId VO (and no Shared edge) is introduced.
 */
final readonly class WelcomeEmail implements RenderableEmail
{
    public function __construct(
        public string $sagaId,
        public int $subscriptionId,
        public EmailAddress $recipientEmail,
        public RepositoryName $repository,
    ) {
    }

    public function key(): WelcomeNotificationKey
    {
        return new WelcomeNotificationKey($this->subscriptionId);
    }
}
