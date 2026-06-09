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
        $message = new AMQPMessage($body, $properties);

        $this->channel->basic_publish($message, $exchange, $routingKey);

        try {
            $this->channel->wait_for_pending_acks($this->confirmTimeoutSeconds);
        } catch (AMQPTimeoutException) {
            throw RabbitPublishFailedException::confirmTimedOut($exchange, $routingKey, $this->confirmTimeoutSeconds);
        }

        $nacked = $this->nackedMessages->contains($message);
        $this->ackedMessages->detach($message);
        $this->nackedMessages->detach($message);

        if ($nacked) {
            throw RabbitPublishFailedException::nacked($exchange, $routingKey);
        }
    }
}
