<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class RabbitReleaseNotificationPublisher implements ReleaseNotificationPublisher
{
    public function __construct(
        private RabbitPublisher $publisher,
        private SendReleaseEmailSerializer $serializer,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<SendReleaseEmail> $messages */
    #[\Override]
    public function publishAll(array $messages): void
    {
        $this->publisher->publishBatch(
            RabbitConnection::EXCHANGE_NOTIFICATIONS,
            RabbitConnection::ROUTING_KEY_RELEASE_EMAIL,
            array_map($this->serializer->toJson(...), $messages),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );

        // One log line per recipient so every eventId is observable and a
        // published count is derivable from logs. Emitted only after the batch
        // confirm-wait succeeds: a nack throws above and nothing is logged.
        foreach ($messages as $message) {
            $this->logger->info('release email published', [
                'event_id' => $message->eventId,
                'subscription_id' => $message->subscriptionId,
                'repository' => $message->repository->value(),
                'tag' => $message->release->tagName->value(),
            ]);
        }
    }
}
