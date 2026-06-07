<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Versioned, wire-serialized integration message published to the extracted
 * notification microservice (architecture §7 — RabbitMQ, schema "SendReleaseEmail/v1").
 *
 * This is deliberately NOT a DomainEvent and NOT an AggregateRoot (see
 * App\Shared\Domain\DomainEvent's docblock, which names this class explicitly).
 * A DomainEvent is an in-process, synchronous, PSR-14 concept that never leaves
 * the process. SendReleaseEmail crosses a process/queue boundary: it is a plain,
 * anemic, self-sufficient carrier of data — a versioned schema, an eventId for
 * cross-service correlation/idempotency (AR-MQ2), and the already-resolved
 * recipient — fundamentally a value-object snapshot (like Release), not an
 * entity with identity or lifecycle.
 *
 * @psalm-api
 */
final readonly class SendReleaseEmail
{
    public const string SCHEMA = 'SendReleaseEmail/v1';

    public function __construct(
        public string $schema,
        public string $eventId,
        public \DateTimeImmutable $occurredAt,
        public int $subscriptionId,
        public EmailAddress $email,
        public RepositoryName $repository,
        public ReleaseSnapshot $release
    ) {
    }
}
