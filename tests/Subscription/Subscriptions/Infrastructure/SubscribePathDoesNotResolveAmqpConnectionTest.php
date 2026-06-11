<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Infrastructure;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * NFR3 / AC5 resilience regression lock for the live proof in
 * bin/resilience-proof.sh (Scenario A): POST /api/subscriptions must keep
 * serving while RabbitMQ is down. That holds only because the monolith's
 * subscribe path never resolves the broker publisher — the listener callables
 * in config/container.php (~lines 250-269) are LAZY, so the RabbitMQ publisher /
 * AMQPStreamConnection is constructed solely when a scan dispatches
 * NewReleaseDetected, never while wiring/serving the HTTP subscribe path.
 *
 * Deterministic by construction — no Docker, DB, broker, DNS or socket:
 * RabbitConnection::class is rebound to a tripwire that throws a distinctive
 * sentinel the instant it is resolved (it stands in for "an AMQP socket was
 * opened"), and the rabbitmq settings point at an unroutable port as defence
 * in depth. The asymmetry the proof relies on is then asserted directly:
 * dispatching SubscriptionCreated never touches the tripwire, while resolving
 * the publisher the NewReleaseDetected listener depends on always does.
 */
final class SubscribePathDoesNotResolveAmqpConnectionTest extends TestCase
{
    private const TRIPWIRE_MESSAGE = 'AMQP connection constructed — subscribe path must never reach the broker';

    private function buildContainer(): Container
    {
        $settings = require dirname(__DIR__, 4) . '/config/settings.php';
        // Unroutable broker — even if the tripwire below were bypassed, no real
        // RabbitMQ is ever contacted, so the test stays hermetic.
        $settings['rabbitmq']['host'] = '127.0.0.1';
        $settings['rabbitmq']['port'] = 1;

        $containerFactory = require dirname(__DIR__, 4) . '/config/container.php';
        $container = $containerFactory($settings);

        // Tripwire: resolving the AMQP connection at all is the failure we guard
        // against. Throwing here is deterministic and instantaneous — no socket
        // is ever opened.
        $container->set(RabbitConnection::class, static function (): RabbitConnection {
            throw new \RuntimeException(self::TRIPWIRE_MESSAGE);
        });

        return $container;
    }

    public function testDispatchingSubscriptionCreatedNeverConstructsTheAmqpConnection(): void
    {
        $container = $this->buildContainer();

        // The subscribe path's only domain-event touchpoint: SubscribeCommandHandler
        // dispatches SubscriptionCreated through this dispatcher. Resolving it must
        // not eagerly build the publisher map either.
        $dispatcher = $container->get(EventDispatcherInterface::class);

        $event = new SubscriptionCreated(
            'resilience-subscribe@example.test',
            'docker/compose',
            new \DateTimeImmutable('2026-06-11T00:00:00+00:00')
        );

        // Dispatch runs ONLY the WhenSubscriptionCreatedThenLog listener (logger,
        // no broker). If the listener wiring were eager, resolving the dispatcher
        // or dispatching here would trip the RabbitConnection tripwire.
        $dispatcher->dispatch($event);

        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
    }

    public function testReleasePublishPathStillReachesTheAmqpConnection(): void
    {
        $container = $this->buildContainer();

        // Positive control / asymmetry proof: the publisher that the
        // NewReleaseDetected listener depends on DOES reach RabbitConnection, so
        // the tripwire is correctly placed and the subscribe-path assertion above
        // is meaningful (not vacuously green because nothing ever touches AMQP).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::TRIPWIRE_MESSAGE);

        $container->get(ReleaseNotificationPublisher::class);
    }
}
