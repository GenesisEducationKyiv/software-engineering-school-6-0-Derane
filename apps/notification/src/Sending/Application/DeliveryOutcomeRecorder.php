<?php

declare(strict_types=1);

namespace App\Sending\Application;

interface DeliveryOutcomeRecorder
{
    public function recordDelivered(): void;

    public function recordDeduped(): void;
}
