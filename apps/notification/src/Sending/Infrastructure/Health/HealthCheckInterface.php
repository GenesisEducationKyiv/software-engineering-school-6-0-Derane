<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Health;

interface HealthCheckInterface
{
    /** @throws \Throwable */
    public function check(): void;
}
