<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Anti-corruption layer between the `notifications.welcome-email-reply` queue and
 * the saga orchestrator. Maps a WelcomeEmailOutcome/v1 reply to a
 * HandleWelcomeEmailOutcomeCommand and dispatches it on the in-house CommandBus
 * (T3 confirm / C1 compensate, idempotent — FR8/FR9/FR11).
 *
 * Reply-queue disposition (the reply queue has NO DLX of its own, arch §7):
 *
 *  - Malformed reply (fails EXPECTED_SCHEMA / bad sagaId / unknown outcome): logged
 *    and acked-and-dropped. It does NOT increment welcome_reply_consumed_total — the
 *    timeout sweeper is the backstop for the (bounded) false-cancel risk.
 *  - Well-formed reply: increments welcome_reply_consumed_total at THIS call-site
 *    (M1 ownership) for EVERY well-formed reply INCLUDING no-ops, then dispatches.
 *    The handler's both-rowCount()=0 no-op increments welcome_reply_noop_total (the
 *    subset counter) and is acked-and-dropped here — never redelivered.
 *  - An unexpected (environmental) throw from the handler leaves the delivery
 *    unacked via nack(requeue:true) so a transient DB blip is retried; idempotency
 *    makes the redelivery safe.
 *
 * @psalm-api
 */
final readonly class WelcomeEmailOutcomeConsumer
{
    public const QUEUE = 'notifications.welcome-email-reply';

    public function __construct(
        private MessageConsumer $consumer,
        private WelcomeEmailOutcomeMessageMapper $mapper,
        private CommandBus $commandBus,
        private SagaMetricsRecorder $metrics,
        private LoggerInterface $logger,
    ) {
    }

    public function start(): void
    {
        $this->consumer->consume(self::QUEUE, $this->handleDelivery(...));
    }

    public function handleDelivery(AMQPMessage $message): void
    {
        try {
            $command = $this->mapper->fromJson($message->getBody());
        } catch (MalformedWelcomeEmailOutcomeException $e) {
            // No DLX on the reply queue: ack-and-drop is clearer than a discarding
            // nack and avoids a redelivery loop. Does NOT count as consumed.
            $this->logger->error(
                'Malformed WelcomeEmailOutcome reply acked and dropped',
                ['error' => $e->getMessage()],
            );
            $this->consumer->ack($message);

            return;
        }

        // Every well-formed reply (including no-ops) is a "consumed" reply (M1).
        $this->record(fn () => $this->metrics->recordWelcomeReplyConsumed());

        $context = [
            'saga_id' => $command->sagaId,
            'subscription_id' => $command->subscriptionId,
            'outcome' => $command->outcome->value,
        ];

        try {
            $this->commandBus->dispatch($command);
            // Success or a both-rowCount()=0 no-op both ack-and-drop here (the
            // handler increments welcome_reply_noop_total on the no-op subset).
            $this->consumer->ack($message);
            $this->logger->info('WelcomeEmailOutcome reply applied', $context);
        } catch (\Throwable $e) {
            // Environmental fault (e.g. a transient DB error). Leave unacked for
            // redelivery; the conditional-UPDATE guard makes the replay a no-op.
            $context['error'] = $e->getMessage();
            $this->logger->error('WelcomeEmailOutcome reply failed — left for redelivery', $context);
            $this->consumer->nack($message, requeue: true);
        }
    }

    /** Metrics are best-effort: a recorder failure must never crash the consume loop. */
    private function record(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record saga reply metric', ['error' => $e->getMessage()]);
        }
    }
}
