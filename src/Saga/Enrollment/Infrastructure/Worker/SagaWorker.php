<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Worker;

use App\Saga\Enrollment\Application\Relay\RelayPendingWelcomeEmails;
use App\Saga\Enrollment\Application\Sweep\SweepTimedOutSagas;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;

/**
 * The monolith's first long-lived worker (PRD R8) — one supervised loop with three
 * responsibilities (relay + reply consumer + timeout sweep), modeled on
 * apps/notification/bin/consumer.php: PCNTL SIGTERM/SIGINT graceful flag, a
 * PCNTLHeartbeatSender so heartbeats flow during wait()/relay, and exit(1) on
 * AMQPConnectionClosedException | AMQPIOException | AMQPHeartbeatMissedException for
 * supervised restart (docker restart: unless-stopped re-derives all pending work
 * from the durable saga rows).
 *
 * Per tick the loop:
 *  1. runs RelayPendingWelcomeEmails (publish STARTED sagas; markPublished on a
 *     confirmed publish; recordRelayFailure on an unconfirmed one — D1/D2 FR4),
 *  2. wait(timeout) to consume WelcomeEmailOutcome replies (the D3 consumer is a
 *     basic_consume callback on the same channel),
 *  3. every N ticks runs SweepTimedOutSagas (primary T + secondary T_start — D4).
 *
 * tick() is split out from run() so the per-tick orchestration (relay always; sweep
 * only every N ticks) is unit-testable against mocked use-cases without a broker.
 *
 * @psalm-api
 */
final readonly class SagaWorker
{
    public function __construct(
        private RelayPendingWelcomeEmails $relay,
        private WelcomeEmailOutcomeConsumer $replyConsumer,
        private SweepTimedOutSagas $sweeper,
        private RabbitConnection $connection,
        private LoggerInterface $logger,
        private int $relayBatchSize,
        private int $timeoutSeconds,
        private int $startTimeoutSeconds,
        private int $waitSeconds,
        private int $sweepEveryTicks,
    ) {
    }

    /**
     * One iteration of the loop's work: relay every tick, sweep every N ticks.
     * $tickNumber is 1-based; the sweep runs when $tickNumber % $sweepEveryTicks == 0
     * (and never if $sweepEveryTicks <= 0).
     */
    public function tick(int $tickNumber): void
    {
        $this->relay->relay($this->relayBatchSize);

        if ($this->sweepEveryTicks > 0 && $tickNumber % $this->sweepEveryTicks === 0) {
            $this->sweeper->sweep($this->timeoutSeconds, $this->startTimeoutSeconds);
        }
    }

    /**
     * The supervised loop. Returns an exit code: 0 on a clean shutdown-signal exit,
     * 1 when the connection is lost (so the caller can exit(1) for a supervised
     * restart). bin/saga-worker.php is a thin shell over this.
     */
    public function run(): int
    {
        $channel = $this->connection->channel();

        // Graceful shutdown: SIGTERM/SIGINT flip the flag; the loop re-checks it at
        // least once per wait window so an in-flight delivery always finishes
        // (ack/nack) before exit. Idempotency makes a hard kill safe regardless.
        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$running): void {
                    $this->logger->info('Shutdown signal received — finishing in-flight work and exiting');
                    $running = false;
                });
            }
        }

        // Keep heartbeats flowing via SIGALRM even while relaying or parked in
        // wait(), so the broker never drops this long-lived connection.
        $heartbeat = null;
        $connection = $channel->getConnection();
        if ($connection !== null && extension_loaded('pcntl')) {
            $heartbeat = new PCNTLHeartbeatSender($connection);
            $heartbeat->register();
        }

        $this->replyConsumer->start();
        $this->logger->info('Saga worker started', ['reply_queue' => WelcomeEmailOutcomeConsumer::QUEUE]);

        $tick = 0;
        try {
            while ($running) {
                $tick++;
                $this->tick($tick);

                if (!$channel->is_consuming()) {
                    // The channel stopped consuming because the connection went away.
                    break;
                }

                try {
                    $channel->wait(timeout: $this->waitSeconds);
                } catch (AMQPTimeoutException) {
                    // No reply within the poll window — loop to relay/sweep/honor signals.
                }
            }
        } catch (AMQPConnectionClosedException | AMQPIOException | AMQPHeartbeatMissedException $e) {
            $heartbeat?->unregister();
            $this->logger->error('Saga worker connection lost — exiting for supervised restart', [
                'error' => $e->getMessage(),
            ]);

            return 1;
        }

        $heartbeat?->unregister();

        if ($running) {
            // The loop ended without a shutdown signal: the channel stopped
            // consuming. Same supervised-restart path.
            $this->logger->error('Saga worker stopped unexpectedly — exiting for supervised restart');

            return 1;
        }

        try {
            $channel->close();
            $connection?->close();
        } catch (\Throwable) {
            // Connection already gone on a clean shutdown — nothing to flush.
        }
        $this->logger->info('Saga worker stopped');

        return 0;
    }
}
