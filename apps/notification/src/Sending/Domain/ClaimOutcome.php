<?php

declare(strict_types=1);

namespace App\Sending\Domain;

enum ClaimOutcome
{
    /** This caller won the claim and must proceed to send. */
    case Claimed;

    /** The notification was already delivered — skip as a dedupe. */
    case AlreadySent;

    /** Another worker holds a live claim — retry later, do not send. */
    case InFlight;

    /**
     * The notification is terminally failed (welcome ledger only — the row carries
     * terminal_failed_at). Never re-claimed, never re-sent: re-emit the prior
     * `failed` reply and route to the DLQ. The release ledger never returns this.
     */
    case AlreadyFailed;
}
