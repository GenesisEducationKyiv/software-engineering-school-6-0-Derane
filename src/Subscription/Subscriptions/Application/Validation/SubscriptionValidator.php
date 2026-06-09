<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Validation;

use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;

/**
 * Throws Shared\Domain\Exception\ValidationException (via the legacy Email/RepositoryName
 * validators) so the 400 / INVALID_ARGUMENT mapping in ExceptionStatusMap is preserved.
 *
 * Lives in the Application layer (use-case input concern), so the handler depends on it
 * without crossing the Application -> Infrastructure boundary.
 *
 * Depends on App\Validation\* (Legacy.Application) — a transitional deptrac edge granted
 * explicitly until the validators are absorbed into the Shared VOs.
 *
 * @psalm-api
 */
final readonly class SubscriptionValidator
{
    public function __construct(
        private EmailValidator $emailValidator,
        private RepositoryNameValidator $repositoryNameValidator
    ) {
    }

    public function assertValidSubscription(string $email, string $repository): void
    {
        $this->emailValidator->assertValid($email);
        $this->repositoryNameValidator->assertValid($repository);
    }
}
