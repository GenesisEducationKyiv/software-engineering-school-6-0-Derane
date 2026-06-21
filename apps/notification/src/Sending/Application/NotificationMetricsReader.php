<?php

declare(strict_types=1);

namespace App\Sending\Application;

interface NotificationMetricsReader
{
    public function consumedCount(): int;

    public function deliveredCount(): int;

    public function dedupedCount(): int;

    public function failedCount(): int;

    public function contentionCount(): int;

    public function dlqCount(): int;

    public function supersededCount(): int;

    public function welcomeConsumedCount(): int;

    public function welcomeSentCount(): int;

    public function welcomeDedupedCount(): int;

    public function welcomeFailedCount(): int;

    public function welcomeReplyPublishedCount(): int;
}
