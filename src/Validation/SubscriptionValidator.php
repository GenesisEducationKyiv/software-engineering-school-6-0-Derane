<?php

declare(strict_types=1);

namespace App\Validation;

final class SubscriptionValidator
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
