<?php

declare(strict_types=1);

namespace Tests\Integration\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Application\SagaMetricsReader;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Infrastructure\Persistence\PdoSagaMetricsStore;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * The 006 saga_metrics counter store: each record* is an atomic UPSERT increment,
 * each read returns 0 for an unseen metric (FR12/AC7).
 */
final class PdoSagaMetricsStoreTest extends IntegrationTestCase
{
    private PdoSagaMetricsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c->get(PDO::class)->exec('TRUNCATE saga_metrics');
        /** @var PdoSagaMetricsStore $store */
        $store = $this->c->get(PdoSagaMetricsStore::class);
        $this->store = $store;
    }

    public function testUnseenCountersReadZero(): void
    {
        $this->assertSame(0, $this->store->welcomeCommandPublishedCount());
        $this->assertSame(0, $this->store->welcomeReplyConsumedCount());
        $this->assertSame(0, $this->store->welcomeReplyNoopCount());
        $this->assertSame(0, $this->store->timeoutSweptCount());
    }

    public function testEachRecordIncrementsItsOwnCounter(): void
    {
        $this->store->recordWelcomeCommandPublished();
        $this->store->recordWelcomeCommandPublished();
        $this->store->recordWelcomeReplyConsumed();
        $this->store->recordWelcomeReplyNoop();
        $this->store->recordTimeoutSwept();
        $this->store->recordTimeoutSwept();
        $this->store->recordTimeoutSwept();

        $this->assertSame(2, $this->store->welcomeCommandPublishedCount());
        $this->assertSame(1, $this->store->welcomeReplyConsumedCount());
        $this->assertSame(1, $this->store->welcomeReplyNoopCount());
        $this->assertSame(3, $this->store->timeoutSweptCount());
    }

    public function testRecorderAndReaderPortsResolveToTheSameInstance(): void
    {
        $this->assertSame(
            $this->c->get(SagaMetricsRecorder::class),
            $this->c->get(SagaMetricsReader::class),
        );
    }
}
