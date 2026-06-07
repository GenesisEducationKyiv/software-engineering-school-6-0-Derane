<?php

declare(strict_types=1);

namespace App\Sending\Application;

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
    ) {
    }

    public function handle(ReleaseEmail $email): void
    {
        if ($this->ledger->hasBeenSent($email->subscriptionId, $email->tagName, $email->repository)) {
            return;
        }

        $rendered = $this->renderer->render($email);

        $this->mailer->send($email->recipientEmail, $rendered);

        $this->ledger->markSent($email->subscriptionId, $email->tagName, $email->repository, $email->recipientEmail);
    }
}
