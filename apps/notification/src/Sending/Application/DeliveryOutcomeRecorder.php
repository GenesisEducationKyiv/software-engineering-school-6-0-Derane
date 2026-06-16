<?php

declare(strict_types=1);

namespace App\Sending\Application;

interface DeliveryOutcomeRecorder
{
    public function recordDelivered(): void;

    public function recordDeduped(): void;

    /**
     * A delivery whose ledger write was fenced: this worker sent the email but
     * its claim lease had already been taken over, so the send was a superseded
     * duplicate. Tracked separately from delivered so the duplicate rate stays
     * visible instead of inflating delivered_total.
     */
    public function recordSuperseded(): void;
}
