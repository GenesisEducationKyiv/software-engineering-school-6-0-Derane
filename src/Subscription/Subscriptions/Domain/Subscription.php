<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Not readonly: PHP forbids readonly child classes of non-readonly parents;
 * AggregateRoot is non-readonly.
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
