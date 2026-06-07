<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Health;

final readonly class CompositeHealthCheck implements HealthCheckInterface
{
    /** @param list<HealthCheckInterface> $checks */
    public function __construct(private array $checks)
    {
    }

    #[\Override]
    public function check(): void
    {
        foreach ($this->checks as $check) {
            $check->check();
        }
    }
}
