<?php

declare(strict_types=1);

/**
 * Example 4: Fixing Infrastructure → Application Handler violations
 *
 * VIOLATION:
 *   Notification\Publishing.Infrastructure must not depend on
 *   Subscription.Application
 *     src/Notification/Publishing/Infrastructure/Listener/SomeListener.php:10
 *       uses App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler
 *
 * Fix: never inject a concrete cross-context Application handler into an
 * Infrastructure adapter.
 *   - Option 1: depend on the in-house CommandBus (Shared.Domain) and dispatch.
 *   - Option 2 (preferred for reactions): record a domain event in the aggregate
 *     and react with a thin Infrastructure\Listener wired on the PSR-14 plane —
 *     the Application use-case stays free of the foreign Domain.
 *
 * NOTE: in-process domain events use the synchronous PSR-14 plane (listener
 * exceptions propagate, which keeps the flow outbox-free). Cross-service work is
 * a separate plane: RabbitMQ integration messages via php-amqplib (e.g.
 * SendReleaseEmail). This is NOT Symfony Messenger — the buses are in-house.
 */

// ============================================================================
// BEFORE (WRONG) — Infrastructure injects concrete Application handlers
// ============================================================================

namespace Example\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;     // own-context: allowed
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler; // VIOLATION! cross-context Application
use App\Releases\Sourcing\Domain\NewReleaseDetected;

final class WhenNewReleaseDetectedListenerBefore
{
    public function __construct(
        private PublishReleaseEmailsForRelease $publish,
        private SubscribeCommandHandler $subscribeHandler // VIOLATION! concrete handler
    ) {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        // Direct handler invocation across a context boundary — WRONG!
        ($this->subscribeHandler)(/* ... */);
    }
}

// ============================================================================
// AFTER — OPTION 1: Depend on the in-house CommandBus (Shared.Domain)
// ============================================================================

namespace Example\SomeContext\SomeModule\Infrastructure\Adapter;

use App\Shared\Domain\Bus\Command\CommandBus;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;

/**
 * The adapter depends on the CommandBus interface (Shared.Domain), never on a
 * concrete handler. DI binds the InMemoryCommandBus implementation.
 *
 * @psalm-api
 */
final readonly class SomeInfrastructureAdapter
{
    public function __construct(private CommandBus $commandBus)
    {
    }

    public function onExternalTrigger(string $email, string $repository): void
    {
        // Decoupled: the bus routes the command to its registered handler.
        $this->commandBus->dispatch(new SubscribeCommand($email, $repository));
    }
}

// ============================================================================
// AFTER — OPTION 2 (Preferred): Domain events + thin Infrastructure listener
//
// This mirrors the REAL pattern in
// src/Notification/Publishing/Infrastructure/Listener/.
// ============================================================================

// STEP 1: The aggregate records domain events (Shared\Domain\Aggregate\AggregateRoot)
namespace Example\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,
        private readonly RepositoryName $repository,
        private readonly string $createdAt
    ) {
    }

    public static function subscribe(EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        $subscription = new self(null, $email, $repository, $createdAt);
        // Record the event — dispatched after the aggregate is persisted.
        $subscription->recordThat(new SubscriptionCreated(
            (string) $email,
            (string) $repository,
            new \DateTimeImmutable()
        ));

        return $subscription;
    }
}

// STEP 2: The domain event — pure Domain, implements Shared\Domain\DomainEvent
namespace Example\Subscription\Subscriptions\Domain;

use App\Shared\Domain\DomainEvent;

/** @psalm-api */
final readonly class SubscriptionCreated implements DomainEvent
{
    public function __construct(
        public string $email,
        public string $repository,
        private \DateTimeImmutable $occurredOn
    ) {
    }

    #[\Override]
    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'subscription.created';
    }
}

// STEP 3: A thin Infrastructure\Listener reacts and delegates to its OWN
// Application use-case (the REAL PublishReleaseEmailsOnNewReleaseDetectedListener).
namespace Example\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Releases\Sourcing\Domain\NewReleaseDetected;

/**
 * Thin adapter from the Releases-owned NewReleaseDetected event to the
 * Publishing use-case. It maps the event's DetectedRelease into THIS context's
 * own ReleaseSnapshot (anti-corruption) and delegates — keeping the Application
 * layer free of any Releases.Domain dependency. The only cross-context edge is
 * Infrastructure → Releases.Domain (an explicit deptrac grant), never
 * Infrastructure → another context's Application.
 *
 * @psalm-api
 */
final readonly class PublishReleaseEmailsOnNewReleaseDetectedListener
{
    public function __construct(private PublishReleaseEmailsForRelease $publishReleaseEmails)
    {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        $release = $event->detected->release;

        // Map once per dispatch — the same snapshot is shared across all recipients.
        ($this->publishReleaseEmails)($event->repository, new ReleaseSnapshot(
            $event->detected->tag,
            $release->name,
            $release->htmlUrl,
            $release->publishedAt,
            $release->body,
        ));
    }
}

// STEP 4: The Application use-case depends only on its own Domain ports + a
// granted Subscription.Domain port to resolve recipients. No Infrastructure.
namespace Example\Notification\Publishing\Application;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;

/**
 * Resolve the repository's subscribers and publish one SendReleaseEmail
 * integration message per recipient, as a single batch. No try/catch around
 * publishAll(): a publish failure must propagate so the scan marker is not
 * advanced (this is what keeps the flow outbox-free).
 *
 * @psalm-api
 */
final readonly class PublishReleaseEmailsForRelease
{
    public function __construct(
        private SubscriberFinder $subscribers,                 // Subscription.Domain port (granted)
        private SendReleaseEmailFactoryInterface $messageFactory,
        private ReleaseNotificationPublisher $publisher        // own Domain port
    ) {
    }

    public function __invoke(RepositoryName $repository, ReleaseSnapshot $release): void
    {
        $recipients = $this->subscribers->findSubscribersByRepository($repository);

        $messages = [];
        foreach ($recipients as $subscriber) {
            $messages[] = $this->messageFactory->fromRecipient(
                $subscriber->id,
                new EmailAddress($subscriber->email),
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

// ============================================================================
// INFRASTRUCTURE — the RabbitMQ adapter implements the Domain port
//
// Cross-service plane: php-amqplib. This is the REAL shape of
// src/Notification/Publishing/Infrastructure/RabbitReleaseNotificationPublisher.php.
// ============================================================================

namespace Example\Notification\Publishing\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;

/** @psalm-api */
final readonly class RabbitReleaseNotificationPublisher implements ReleaseNotificationPublisher
{
    public function __construct(
        private RabbitPublisher $publisher,
        private SendReleaseEmailSerializer $serializer
    ) {
    }

    /** @param list<SendReleaseEmail> $messages */
    #[\Override]
    public function publishAll(array $messages): void
    {
        $this->publisher->publishBatch(
            'notifications',
            'release.email',
            array_map($this->serializer->toJson(...), $messages),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );
    }
}

// ============================================================================
// BENEFITS OF OPTION 2 (Domain events):
//
// 1. Complete decoupling — Infrastructure never imports another context's Application
// 2. Business intent is explicit — NewReleaseDetected, not "postPersist"
// 3. Easy to add new reactions — register another PSR-14 listener
// 4. Testable — test the aggregate records events, test the listener reacts
// 5. Two planes kept distinct — PSR-14 in-process vs RabbitMQ integration messages
// 6. No Deptrac violations — only allowed Domain-port edges cross contexts
// ============================================================================

// ============================================================================
// SUMMARY:
// - Infrastructure uses interfaces (CommandBus / QueryBus from Shared.Domain).
// - Never inject a concrete cross-context Application handler into Infrastructure.
// - Prefer domain events + a thin Infrastructure\Listener for reactions.
// - The listener may touch its own Application + a granted foreign Domain edge —
//   never a foreign Application handler.
// - DI binds interfaces only; alias a second interface to share one instance.
// ============================================================================
