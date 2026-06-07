<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface MessageProcessingStatsRecorder
{
    public function recordConsumed(): void;

    public function recordFailed(): void;

    public function recordDlq(): void;
}
