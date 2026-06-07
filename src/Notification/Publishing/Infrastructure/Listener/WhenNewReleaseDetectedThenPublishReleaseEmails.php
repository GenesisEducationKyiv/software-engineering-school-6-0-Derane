<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Domain\NewReleaseDetected;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactoryInterface;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;

/**
 * PSR-14 listener for NewReleaseDetected: resolves the repository's subscribers,
 * maps the carried Releases\Sourcing\Domain\Release into this context's own
 * ReleaseSnapshot exactly once (anti-corruption mapping — see C1's Completion
 * Notes, which explicitly punted this mapping to C2), builds one SendReleaseEmail
 * integration message per recipient via SendReleaseEmailFactoryInterface, and
 * hands each to ReleaseNotificationPublisher::publish().
 *
 * Plain invokable class (no interface) — exactly the shape ListenerProvider's
 * `array<class-string, list<callable>>` contract expects, and exactly how
 * epics.md names this file: a listener, not an implementation of some
 * "ListenerInterface" that does not exist in this codebase.
 *
 * Placement: Infrastructure\Listener, not Application. It is a PSR-14 *adapter*
 * reacting to the in-process event-bus mechanism — and, decisively, it depends
 * on SendReleaseEmailFactoryInterface, which (like every *FactoryInterface in
 * this codebase — SubscriptionFactoryInterface, SubscriberRefFactoryInterface,
 * ReleaseFactoryInterface, RepositoryStatusFactoryInterface) lives in
 * Infrastructure\Factory. An Application-layer placement would create an
 * Application -> Infrastructure edge, inverting Clean Architecture's dependency
 * rule (deptrac confirms this: it is the one new violation that surfaces if you
 * try Application — exactly the "you've placed a class wrong" signal the story's
 * Dev Notes warn about). NotificationPublishing.Infrastructure already grants
 * .Domain + .Application + Shared.* — no new deptrac edge is needed at all.
 *
 * Deliberately has NO try/catch around publish(): InMemoryEventDispatcher's
 * docblock states the no-catch contract is load-bearing for the outbox-free,
 * pre-commit flow (AR-FLOW2) — a publish failure must propagate out of this
 * listener, out of the dispatcher, and into ScanReleasesHandler's per-repository
 * catch, so the scan marker is not advanced and the release is retried next
 * cycle. Swallowing here would silently break that guarantee.
 *
 * @psalm-api
 */
final readonly class WhenNewReleaseDetectedThenPublishReleaseEmails
{
    public function __construct(
        private SubscriberFinder $subscribers,
        private SendReleaseEmailFactoryInterface $messageFactory,
        private ReleaseNotificationPublisher $publisher
    ) {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        $tag = $event->release->tagName;
        if ($tag === null) {
            // Mirrors NotificationDispatcher::dispatch()'s defensive guard:
            // ReleaseTag is non-nullable and throws on a null/empty value, so a
            // tagless release must be skipped here rather than crash the whole
            // scan loop with an InvalidArgumentException from new ReleaseTag(null).
            return;
        }

        $recipients = $this->subscribers->findSubscribersByRepository($event->repository->value());

        // Map Release -> ReleaseSnapshot exactly ONCE per dispatch — it is the
        // same snapshot for every subscriber of the same release (see Dev Notes
        // "The Release -> ReleaseSnapshot mapping is THIS story's job"). Note:
        // publishedAt is passed through verbatim, no reformatting — this is the
        // existing, accepted wire-format contract (SendReleaseEmailSerializer
        // line 38), not a defect to "fix" here.
        $releaseSnapshot = new ReleaseSnapshot(
            new ReleaseTag($tag),
            $event->release->name,
            $event->release->htmlUrl,
            $event->release->publishedAt
        );

        foreach ($recipients as $subscriber) {
            $message = $this->messageFactory->fromRecipient(
                $subscriber->id,
                new EmailAddress($subscriber->email),
                $event->repository,
                $releaseSnapshot
            );

            $this->publisher->publish($message);
        }
    }
}
