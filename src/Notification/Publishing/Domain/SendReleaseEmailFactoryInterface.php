<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Takes ReleaseSnapshot (not Releases\Sourcing\Domain\Release) to keep
 * Notification\Publishing decoupled from the Releases bounded context.
 *
 * @psalm-api
 */
interface SendReleaseEmailFactoryInterface
{
    public function fromRecipient(
        int $subscriptionId,
        EmailAddress $email,
        RepositoryName $repository,
        ReleaseSnapshot $release
    ): SendReleaseEmail;
}
