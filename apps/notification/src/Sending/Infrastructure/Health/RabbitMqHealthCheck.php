<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Health;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final readonly class RabbitMqHealthCheck implements HealthCheckInterface
{
    private const QUEUE = 'notifications.send-email';

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost,
    ) {
    }

    #[\Override]
    public function check(): void
    {
        try {
            $connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost
            );
            $channel = $connection->channel();
            $channel->queue_declare(self::QUEUE, true);
            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            throw new \RuntimeException('RabbitMQ is unreachable', 0, $e);
        }
    }
}
