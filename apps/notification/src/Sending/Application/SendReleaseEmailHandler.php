<?php

declare(strict_types=1);

namespace App\Sending\Application;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\ReleaseEmail;

final readonly class SendReleaseEmailHandler
{
    public function __construct(
        private NotificationLedger $ledger,
        private EmailRenderer $renderer,
        private Mailer $mailer,
        private DeliveryOutcomeRecorder $outcomes,
    ) {
    }

    public function handle(ReleaseEmail $email): void
    {
        $key = $email->key();

        // Atomic claim, not check-then-act: only the worker that wins the
        // claim may reach the mailer, so concurrent consumers (or a
        // redelivery racing a slow first attempt) cannot double-send. The
        // won claim's fencing token guards every subsequent ledger write.
        $claim = $this->ledger->claim($key, $email->recipientEmail);

        if ($claim->outcome === ClaimOutcome::AlreadySent) {
            $this->outcomes->recordDeduped();
            return;
        }

        if ($claim->outcome === ClaimOutcome::InFlight) {
            throw NotificationInFlightException::forKey($key);
        }

        $rendered = $this->renderer->render($email);

        try {
            $this->mailer->send($email->recipientEmail, $rendered);
        } catch (\Throwable $e) {
            $this->ledger->recordFailedAttempt($key, $email->recipientEmail, $e->getMessage(), $claim->token());
            throw $e;
        }

        if ($this->ledger->markSent($key, $email->recipientEmail, $claim->token())) {
            $this->outcomes->recordDelivered();

            return;
        }

        // Fenced: our lease was taken over mid-send, so the email we just sent is
        // a superseded duplicate — the new claim holder owns the ledger row and
        // records its own delivery. Count it separately rather than inflating
        // delivered_total. Returning normally still acks; retrying would only
        // send a third copy.
        $this->outcomes->recordSuperseded();
    }
}
