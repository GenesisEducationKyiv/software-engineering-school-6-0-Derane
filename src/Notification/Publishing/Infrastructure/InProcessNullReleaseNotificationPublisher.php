<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use Psr\Log\LoggerInterface;

/**
 * TEMPORARY PLACEHOLDER — exists ONLY so the DI container can resolve the
 * ReleaseNotificationPublisher port (and therefore
 * WhenNewReleaseDetectedThenPublishReleaseEmails, and therefore the whole
 * application graph) before the real RabbitMQ adapter exists.
 *
 * ReleaseNotificationPublisher's own docblock says it "intentionally has no
 * bound implementation yet — C2 wires the listener that calls publish(), C5
 * introduces the RabbitMQ adapter". Read literally that sounds like the binding
 * itself can stay absent — it cannot: PHP-DI eagerly resolves the whole
 * constructor graph the moment anything asks for
 * WhenNewReleaseDetectedThenPublishReleaseEmails (which the ListenerProvider
 * binding must do), and an unbound interface anywhere in that chain is a hard
 * NotFoundException, not a lazy no-op.
 *
 * So this adapter no-ops (logging at debug level only — "would publish ... —
 * stub, see C5"), which keeps the Epic C gate note's promise that "the
 * in-process adapter stays the DI default so runtime behavior is preserved": no
 * SendReleaseEmail message actually reaches RabbitMQ or any consumer through
 * this stub, exactly like the current absence of a bound publisher changes
 * nothing observable today.
 *
 * C5 — DO NOT extend or repurpose this class. Replace the
 * `ReleaseNotificationPublisher::class` binding in config/container.php with
 * `RabbitReleaseNotificationPublisher` and delete this file outright. It is not
 * a "default adapter" or a fallback — it is scaffolding that exists solely to
 * keep the container graph resolvable until the real adapter lands. (See C1's
 * "do not create RabbitReleaseNotificationPublisher or touch RabbitMQ/
 * composer.json" guidance — that boundary transfers unchanged to this stub:
 * it must not accrete any RabbitMQ-shaped configuration, connection setup, or
 * serialization "to save C5 some work".)
 *
 * @psalm-api
 */
final readonly class InProcessNullReleaseNotificationPublisher implements ReleaseNotificationPublisher
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[\Override]
    public function publish(SendReleaseEmail $message): void
    {
        $this->logger->debug('Would publish SendReleaseEmail to RabbitMQ — stub, see C5', [
            'schema' => $message->schema,
            'eventId' => $message->eventId,
            'subscriptionId' => $message->subscriptionId,
            'repository' => $message->repository->value(),
        ]);
    }
}
