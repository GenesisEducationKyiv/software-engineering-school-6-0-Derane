<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Validation;

use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;

/**
 * Injected validation collaborator invoked by SubscribeCommandHandler before the
 * aggregate is built. It throws Shared\Domain\Exception\ValidationException (via
 * the legacy Email/RepositoryName validators) so the 400 / INVALID_ARGUMENT mapping in
 * ExceptionStatusMap is preserved exactly.
 *
 * Lives in the Application layer (it is a use-case input concern), so the handler
 * depends on it without crossing the Application -> Infrastructure boundary.
 *
 * B5 tech-debt: it depends on App\Validation\* (Legacy.Application) — a
 * transitional Subscription.Application -> Legacy.Application deptrac edge,
 * granted explicitly until B5 moves the validators / fully absorbs them into the
 * Shared VOs.
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
