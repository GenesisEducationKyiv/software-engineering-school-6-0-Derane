<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Cross-service integration message (monolith -> notification), not a
 * DomainEvent: it crosses the queue boundary and carries a versioned schema.
 *
 * Correlation is by sagaId (AMQP correlation_id) for trace continuity, and by
 * the primitive subscriptionId for service-side dedup. It references only
 * Shared.Domain value objects — never a Subscription value object — so
 * Saga.Domain keeps its single Shared.Domain edge.
 *
 * @psalm-api
 */
final readonly class SendWelcomeEmail
{
    public const string SCHEMA = 'SendWelcomeEmail/v1';

    public function __construct(
        public string $schema,
        public string $sagaId,
        public int $subscriptionId,
        public EmailAddress $email,
        public RepositoryName $repository,
        public \DateTimeImmutable $occurredAt
    ) {
    }
}
