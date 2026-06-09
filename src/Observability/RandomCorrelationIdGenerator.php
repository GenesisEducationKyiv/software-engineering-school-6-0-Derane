<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Default {@see CorrelationIdGeneratorInterface}: 128 bits of randomness as a
 * 32-char hex string — the same shape upstreams typically send as `X-Request-Id`.
 *
 * @psalm-api
 */
final readonly class RandomCorrelationIdGenerator implements CorrelationIdGeneratorInterface
{
    #[\Override]
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
