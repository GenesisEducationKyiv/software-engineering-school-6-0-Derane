<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Builds SendReleaseEmail messages: stamps the wire schema, generates a fresh
 * eventId (UUID v4 — cross-service correlation/idempotency per AR-MQ2) and
 * captures occurredAt at construction time.
 *
 * @psalm-api
 */
final readonly class SendReleaseEmailFactory implements SendReleaseEmailFactoryInterface
{
    #[\Override]
    public function fromRecipient(
        int $subscriptionId,
        EmailAddress $email,
        RepositoryName $repository,
        ReleaseSnapshot $release
    ): SendReleaseEmail {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            $this->generateEventId(),
            new \DateTimeImmutable(),
            $subscriptionId,
            $email,
            $repository,
            $release
        );
    }

    /**
     * Pure-PHP RFC 4122 §4.4 UUID v4 generator. No UUID library is an
     * autoloadable direct dependency (verified — see story C1 Decision 3), so
     * eventId is generated from cryptographically secure random bytes with the
     * version/variant bits set per spec, formatted as
     * xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (y in [8901abAB]).
     */
    private function generateEventId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        /** @var list<string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}
