<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
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

    // RFC 4122 §4.4 UUID v4: set version bits (byte 6 → 0x4x) and variant
    // bits (byte 8 → 0x8x/0x9x/0xax/0xbx) before hex-encoding.
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
