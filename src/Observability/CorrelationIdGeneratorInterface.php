<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Produces a fresh correlation id for a new unit of work, used as the fallback
 * when no id arrives from upstream (no HTTP `X-Request-Id`, no gRPC
 * `x-request-id` metadata). Abstracted so the generation strategy is swappable
 * and tests can pin a deterministic id.
 */
interface CorrelationIdGeneratorInterface
{
    public function generate(): string;
}
