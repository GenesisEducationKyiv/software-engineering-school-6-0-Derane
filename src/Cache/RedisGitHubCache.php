<?php

declare(strict_types=1);

namespace App\Cache;

use Predis\ClientInterface as RedisClientInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class RedisGitHubCache implements GitHubCacheInterface
{
    private bool $readFailureLogged = false;
    private bool $writeFailureLogged = false;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function get(string $key): ?string
    {
        try {
            $cached = $this->redis->get($key);
            return $cached === null ? null : $cached;
        } catch (\Throwable $e) {
            $this->logFailure('read', $e);
            return null;
        }
    }

    #[\Override]
    public function set(string $key, int $ttl, string $value): void
    {
        try {
            $this->redis->setex($key, $ttl, $value);
        } catch (\Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    private function logFailure(string $operation, \Throwable $e): void
    {
        $alreadyLogged = $operation === 'read' ? $this->readFailureLogged : $this->writeFailureLogged;
        if ($alreadyLogged) {
            return;
        }

        $this->logger->warning('GitHub cache degraded; Redis operation failed', [
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);

        if ($operation === 'read') {
            $this->readFailureLogged = true;
            return;
        }

        $this->writeFailureLogged = true;
    }
}
