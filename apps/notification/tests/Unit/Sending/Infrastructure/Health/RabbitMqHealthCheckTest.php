<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Health;

use App\Sending\Infrastructure\Health\RabbitMqHealthCheck;
use PHPUnit\Framework\TestCase;

final class RabbitMqHealthCheckTest extends TestCase
{
    public function testThrowsRuntimeExceptionWhenBrokerProbeFails(): void
    {
        $check = new RabbitMqHealthCheck(
            host: '127.0.0.1',
            port: 1,
            user: 'guest',
            password: 'guest',
            vhost: '/',
        );

        $this->expectException(\RuntimeException::class);

        $check->check();
    }
}
