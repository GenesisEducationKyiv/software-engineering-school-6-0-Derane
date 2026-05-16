<?php

declare(strict_types=1);

namespace App\Health;

interface HealthCheckInterface
{
    /** @throws \Throwable when the system is unhealthy */
    public function check(): void;
}
