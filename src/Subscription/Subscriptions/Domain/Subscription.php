<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Subscription aggregate root.
 *
 * `final` (a leaf entity) but NOT `readonly`: it extends AggregateRoot, which
 * owns a mutable event buffer (a PHP child of a non-readonly class cannot be
 * readonly). It holds EmailAddress + RepositoryName VOs internally and exposes
 * primitive accessors that reproduce the frozen JSON values; JSON shaping itself
 * lives in the Infrastructure response mapper, not here.
 *
 * @psalm-api
 */
final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,
        private readonly RepositoryName $repository,
        private readonly string $createdAt
    ) {
    }

    /**
     * Create path: builds a new subscription and records exactly one
     * SubscriptionCreated. The id is null — the database assigns it on persist.
     */
    public static function subscribe(EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        $subscription = new self(null, $email, $repository, $createdAt);
        $subscription->recordThat(new SubscriptionCreated(
            (string) $email,
            (string) $repository,
            new \DateTimeImmutable()
        ));

        return $subscription;
    }

    /**
     * Reconstitution path: rebuilds an aggregate from a persisted row. Records
     * nothing — the event already happened. Called only by the ACL factory.
     */
    public static function reconstitute(
        int $id,
        EmailAddress $email,
        RepositoryName $repository,
        string $createdAt
    ): self {
        return new self($id, $email, $repository, $createdAt);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return (string) $this->email;
    }

    public function repository(): string
    {
        return (string) $this->repository;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
