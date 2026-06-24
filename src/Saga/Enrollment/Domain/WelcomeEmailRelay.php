<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Port for the welcome-email relay publish, with a transport-agnostic contract chosen by
 * WELCOME_EMAIL_TRANSPORT (rabbit | rest | grpc):
 *
 *  - normal return = a DEFINITIVE disposition was reached. The async RabbitWelcomeEmailRelay
 *    publishes with confirms and advances the saga on the next reply/tick; the synchronous
 *    RestWelcomeEmailRelay / GrpcWelcomeEmailRelay BLOCK for the sent|failed outcome and apply
 *    it IN-THREAD (HandleWelcomeEmailOutcomeCommand).
 *  - a throw = NO definitive disposition (unconfirmed publish, transport exhaustion, benign
 *    in-flight contention, or a validation/internal error). The saga is NOT advanced — the
 *    relay records the failure and the saga is left for the next tick to retry.
 *
 * @psalm-api
 */
interface WelcomeEmailRelay
{
    public function publish(SendWelcomeEmail $message): void;
}
