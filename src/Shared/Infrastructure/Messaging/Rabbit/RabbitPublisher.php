<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * confirm_select() is a channel-level setting — calling it per-publish would
 * be wasteful and signals a misunderstanding. Called once in the constructor;
 * ack/nack handlers are also registered once here.
 *
 * @psalm-api
 */
final readonly class RabbitPublisher
{
    private const DEFAULT_CONFIRM_TIMEOUT_SECONDS = 5.0;

    /** Max messages covered by a single confirm-wait — see publishBatch(). */
    private const CONFIRM_CHUNK_SIZE = 500;

    private AMQPChannel $channel;
    private float $confirmTimeoutSeconds;

    /** @var \SplObjectStorage<AMQPMessage, true> */
    private \SplObjectStorage $ackedMessages;

    /**
     * SplObjectStorage is mutated by the ack/nack closures registered in the
     * constructor — the reference itself never changes, keeping this class
     * final readonly while the underlying object's contents do change.
     *
     * @var \SplObjectStorage<AMQPMessage, true>
     */
    private \SplObjectStorage $nackedMessages;

    public function __construct(RabbitConnection $connection, ?float $confirmTimeoutSeconds = null)
    {
        $this->channel = $connection->channel();
        $this->confirmTimeoutSeconds = $confirmTimeoutSeconds ?? self::DEFAULT_CONFIRM_TIMEOUT_SECONDS;
        $this->ackedMessages = new \SplObjectStorage();
        $this->nackedMessages = new \SplObjectStorage();

        $this->channel->confirm_select();
        $this->channel->set_ack_handler(function (AMQPMessage $message): void {
            $this->ackedMessages->attach($message);
        });
        $this->channel->set_nack_handler(function (AMQPMessage $message): void {
            $this->nackedMessages->attach($message);
        });
    }

    /**
     * @param array<string, mixed> $properties AMQP message properties
     * @throws RabbitPublishFailedException on broker nack or confirm-wait timeout
     */
    public function publish(string $exchange, string $routingKey, string $body, array $properties = []): void
    {
        $this->publishBatch($exchange, $routingKey, [$body], $properties);
    }

    /**
     * Publishes the batch with one confirm-wait per CONFIRM_CHUNK_SIZE
     * messages instead of one per message. Chunking keeps the flat
     * per-wait timeout honest — a single wait over an arbitrarily large
     * batch could time out spuriously on a healthy broker.
     *
     * @param list<string> $bodies
     * @param array<string, mixed> $properties AMQP message properties applied to every body
     * @throws RabbitPublishFailedException on any broker nack or confirm-wait timeout
     */
    public function publishBatch(string $exchange, string $routingKey, array $bodies, array $properties = []): void
    {
        foreach (array_chunk($bodies, self::CONFIRM_CHUNK_SIZE) as $chunk) {
            $this->publishChunk($exchange, $routingKey, $chunk, $properties);
        }
    }

    /**
     * @param list<string> $bodies
     * @param array<string, mixed> $properties
     */
    private function publishChunk(string $exchange, string $routingKey, array $bodies, array $properties): void
    {
        $messages = [];
        foreach ($bodies as $body) {
            $message = new AMQPMessage($body, $properties);
            $messages[] = $message;
            $this->channel->basic_publish($message, $exchange, $routingKey);
        }

        try {
            $this->channel->wait_for_pending_acks($this->confirmTimeoutSeconds);
        } catch (AMQPTimeoutException) {
            // Release this chunk's tracking refs before failing so the long-lived
            // publisher never leaks AMQPMessage objects in the ack/nack maps.
            $this->releaseTracking($messages);
            throw RabbitPublishFailedException::confirmTimedOut($exchange, $routingKey, $this->confirmTimeoutSeconds);
        }

        $anyNacked = false;
        foreach ($messages as $message) {
            $anyNacked = $anyNacked || $this->nackedMessages->contains($message);
        }
        $this->releaseTracking($messages);

        if ($anyNacked) {
            throw RabbitPublishFailedException::nacked($exchange, $routingKey);
        }
    }

    /**
     * Drops this chunk's messages from both confirm-tracking maps so the
     * SplObjectStorage does not grow without bound in a long-lived publisher.
     *
     * @param list<AMQPMessage> $messages
     */
    private function releaseTracking(array $messages): void
    {
        foreach ($messages as $message) {
            $this->ackedMessages->detach($message);
            $this->nackedMessages->detach($message);
        }
    }
}
