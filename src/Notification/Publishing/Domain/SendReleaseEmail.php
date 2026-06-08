<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Cross-service integration message, not a DomainEvent: it crosses a process
 * boundary, carries a versioned schema, and includes an eventId for
 * idempotency. DomainEvents are in-process only and carry none of these.
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
