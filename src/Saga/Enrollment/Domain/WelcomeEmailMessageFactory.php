<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Builds the SendWelcomeEmail integration message for a due saga. The concrete
 * adapter (Epic D) resolves the recipient (email, repository) for the saga's
 * subscription and stamps the occurredAt timestamp; the relay use-case stays
 * free of that resolution detail.
 *
 * A *FactoryInterface per CLAUDE.md (anemic integration messages are built through
 * a factory, never a `from*` static method).
 *
 * @psalm-api
 */
interface WelcomeEmailMessageFactory
{
    public function forSaga(EnrollmentSaga $saga): SendWelcomeEmail;
}
