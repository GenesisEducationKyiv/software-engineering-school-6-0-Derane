<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Metrics;

use App\Sending\Application\NotificationMetricsReader;
use App\Sending\Infrastructure\Metrics\MetricsService;
use App\Sending\Infrastructure\Metrics\PrometheusFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MetricsServiceTest extends TestCase
{
    /** @var NotificationMetricsReader&MockObject */
    private NotificationMetricsReader $reader;

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = $this->createMock(NotificationMetricsReader::class);
    }

    public function testCollectReturnsAllNotificationMetricFamilies(): void
    {
        $this->reader->method('consumedCount')->willReturn(11);
        $this->reader->method('deliveredCount')->willReturn(7);
        $this->reader->method('dedupedCount')->willReturn(3);
        $this->reader->method('failedCount')->willReturn(2);
        $this->reader->method('contentionCount')->willReturn(5);
        $this->reader->method('dlqCount')->willReturn(1);
        $this->reader->method('supersededCount')->willReturn(4);

        $service = new MetricsService($this->reader, new PrometheusFormatter());
        $output = $service->collect();

        self::assertStringContainsString('notification_consumed_total 11', $output);
        self::assertStringContainsString('notification_delivered_total 7', $output);
        self::assertStringContainsString('notification_deduped_total 3', $output);
        self::assertStringContainsString('notification_failed_total 2', $output);
        self::assertStringContainsString('notification_contention_total 5', $output);
        self::assertStringContainsString('notification_dlq_total 1', $output);
        self::assertStringContainsString('notification_superseded_total 4', $output);
    }

    public function testCollectExposesAllFiveWelcomeFunnelSpellingsExactly(): void
    {
        $this->reader->method('welcomeConsumedCount')->willReturn(9);
        $this->reader->method('welcomeSentCount')->willReturn(8);
        $this->reader->method('welcomeDedupedCount')->willReturn(2);
        $this->reader->method('welcomeFailedCount')->willReturn(1);
        $this->reader->method('welcomeReplyPublishedCount')->willReturn(6);

        $service = new MetricsService($this->reader, new PrometheusFormatter());
        $output = $service->collect();

        self::assertStringContainsString('welcome_consumed_total 9', $output);
        self::assertStringContainsString('welcome_sent_total 8', $output);
        self::assertStringContainsString('welcome_deduped_total 2', $output);
        self::assertStringContainsString('welcome_failed_total 1', $output);
        self::assertStringContainsString('welcome_reply_published_total 6', $output);
    }
}
