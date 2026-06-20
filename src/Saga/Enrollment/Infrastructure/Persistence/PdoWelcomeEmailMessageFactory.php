<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Shared\Domain\Clock;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use PDO;

/**
 * Builds the SendWelcomeEmail integration message for a due saga by resolving the
 * recipient (email, repository) from the subscription the saga is correlated to.
 *
 * The lookup is a direct read of the `subscriptions` table on the shared
 * PDO::class — the saga keys by the PRIMITIVE int subscriptionId (the cross-context
 * correlation key, arch §3), so this stays free of any Subscription.Domain edge:
 * it reads columns, not a Subscription aggregate (Saga.Infrastructure → Shared
 * only). occurredAt is stamped from the injected Clock at build time.
 *
 * @psalm-api
 */
final readonly class PdoWelcomeEmailMessageFactory implements WelcomeEmailMessageFactory
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    #[\Override]
    public function forSaga(EnrollmentSaga $saga): SendWelcomeEmail
    {
        $subscriptionId = $saga->subscriptionId();

        $stmt = $this->pdo->prepare(
            'SELECT email, repository FROM subscriptions WHERE id = :id'
        );
        $stmt->execute([':id' => $subscriptionId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException(
                "Cannot build SendWelcomeEmail: subscription {$subscriptionId} not found "
                . "for saga {$saga->id()->value()}"
            );
        }

        return new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            $saga->id()->value(),
            $subscriptionId,
            new EmailAddress((string) $row['email']),
            new RepositoryName((string) $row['repository']),
            $this->clock->now(),
        );
    }
}
