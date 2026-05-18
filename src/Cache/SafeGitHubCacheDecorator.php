<?php

declare(strict_types=1);

namespace App\Cache;

use Psr\Log\LoggerInterface;

/** @psalm-api */
final class SafeGitHubCacheDecorator implements GitHubCacheInterface
{
    private bool $readFailureLogged = false;
    private bool $writeFailureLogged = false;

    public function __construct(
        private readonly GitHubCacheInterface $inner,
        private readonly LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function get(string $key): ?string
    {
        try {
            return $this->inner->get($key);
        } catch (\Throwable $e) {
            $this->logFailure('read', $e);
            return null;
        }
    }

    #[\Override]
    public function set(string $key, int $ttl, string $value): void
    {
        try {
            $this->inner->set($key, $ttl, $value);
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

        $this->logger->warning('GitHub cache degraded; underlying cache operation failed', [
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
