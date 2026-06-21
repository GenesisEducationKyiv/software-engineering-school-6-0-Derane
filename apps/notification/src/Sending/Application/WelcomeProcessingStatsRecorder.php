<?php

declare(strict_types=1);

namespace App\Sending\Application;

/**
 * Per-consumer ISP recorder for the welcome funnel (FR12, AC7). Kept distinct
 * from the release {@see MessageProcessingStatsRecorder} so each consumer depends
 * only on the counters it emits. Implemented by the same
 * {@see \App\Sending\Infrastructure\Persistence\PdoNotificationMetricsStore}.
 */
interface WelcomeProcessingStatsRecorder
{
    public function recordWelcomeConsumed(): void;

    public function recordWelcomeSent(): void;

    public function recordWelcomeDeduped(): void;

    public function recordWelcomeFailed(): void;

    public function recordWelcomeReplyPublished(): void;
}
