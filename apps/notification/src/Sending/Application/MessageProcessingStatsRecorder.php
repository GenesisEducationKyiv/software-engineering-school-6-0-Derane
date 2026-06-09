<?php

declare(strict_types=1);

namespace App\Sending\Application;

interface MessageProcessingStatsRecorder
{
    public function recordConsumed(): void;

    public function recordFailed(): void;

    /** Benign claim contention — parked, not failed; kept out of failed_total. */
    public function recordContention(): void;

    public function recordDlq(): void;
}
