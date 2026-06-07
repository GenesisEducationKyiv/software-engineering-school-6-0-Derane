<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface NotificationMetricsReader
{
    public function consumedCount(): int;

    public function deliveredCount(): int;

    public function dedupedCount(): int;

    public function failedCount(): int;

    public function dlqCount(): int;
}
