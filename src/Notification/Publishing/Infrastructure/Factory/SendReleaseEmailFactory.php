<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\EventIdGenerator;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Shared\Domain\Clock;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
final readonly class SendReleaseEmailFactory implements SendReleaseEmailFactoryInterface
{
    public function __construct(
        private Clock $clock,
        private EventIdGenerator $eventIds,
    ) {
    }

    #[\Override]
    public function fromRecipient(
        int $subscriptionId,
        EmailAddress $email,
        RepositoryName $repository,
        ReleaseSnapshot $release
    ): SendReleaseEmail {
        return new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            $this->eventIds->generate(),
            $this->clock->now(),
            $subscriptionId,
            $email,
            $repository,
            $release
        );
    }
}
