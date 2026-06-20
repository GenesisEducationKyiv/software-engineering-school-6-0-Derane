<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Persistence;

use App\Sending\Infrastructure\Persistence\PdoNotificationMetricsStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PdoNotificationMetricsStoreTest extends TestCase
{
    /** @var \PDO&MockObject */
    private \PDO $pdo;
    /** @var \PDOStatement&MockObject */
    private \PDOStatement $stmt;
    private PdoNotificationMetricsStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = $this->createMock(\PDO::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->store = new PdoNotificationMetricsStore($this->pdo);
    }

    public function testRecordConsumedUsesAtomicUpsert(): void
    {
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('ON CONFLICT'))
            ->willReturn($this->stmt);
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([':metric' => 'consumed_total']);

        $this->store->recordConsumed();
    }

    public function testRecordDeliveredUsesDedicatedMetricName(): void
    {
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([':metric' => 'delivered_total']);

        $this->store->recordDelivered();
    }

    public function testMissingMetricsDefaultToZero(): void
    {
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchColumn')->willReturn(false);

        self::assertSame(0, $this->store->consumedCount());
        self::assertSame(0, $this->store->deliveredCount());
        self::assertSame(0, $this->store->dedupedCount());
        self::assertSame(0, $this->store->failedCount());
        self::assertSame(0, $this->store->contentionCount());
        self::assertSame(0, $this->store->dlqCount());
        self::assertSame(0, $this->store->supersededCount());
        self::assertSame(0, $this->store->welcomeConsumedCount());
        self::assertSame(0, $this->store->welcomeSentCount());
        self::assertSame(0, $this->store->welcomeDedupedCount());
        self::assertSame(0, $this->store->welcomeFailedCount());
        self::assertSame(0, $this->store->welcomeReplyPublishedCount());
    }

    /**
     * @return iterable<string, array{callable(PdoNotificationMetricsStore): void, string}>
     */
    public static function welcomeRecorders(): iterable
    {
        yield 'consumed' => [
            static fn(PdoNotificationMetricsStore $s) => $s->recordWelcomeConsumed(),
            'welcome_consumed_total',
        ];
        yield 'sent' => [
            static fn(PdoNotificationMetricsStore $s) => $s->recordWelcomeSent(),
            'welcome_sent_total',
        ];
        yield 'deduped' => [
            static fn(PdoNotificationMetricsStore $s) => $s->recordWelcomeDeduped(),
            'welcome_deduped_total',
        ];
        yield 'failed' => [
            static fn(PdoNotificationMetricsStore $s) => $s->recordWelcomeFailed(),
            'welcome_failed_total',
        ];
        yield 'reply' => [
            static fn(PdoNotificationMetricsStore $s) => $s->recordWelcomeReplyPublished(),
            'welcome_reply_published_total',
        ];
    }

    /**
     * @param callable(PdoNotificationMetricsStore): void $record
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('welcomeRecorders')]
    public function testWelcomeRecordersUseTheExactMetricName(callable $record, string $expectedMetric): void
    {
        $this->pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('ON CONFLICT'))
            ->willReturn($this->stmt);
        $this->stmt->expects(self::once())
            ->method('execute')
            ->with([':metric' => $expectedMetric]);

        $record($this->store);
    }
}
