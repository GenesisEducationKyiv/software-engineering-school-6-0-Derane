<?php

declare(strict_types=1);

namespace App\Config;

final class SmtpConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $from,
        public readonly string $user,
        public readonly string $password,
        public readonly string $encryption
    ) {
    }

    /** @param array{host: string, port: int, from: string, user: string, password: string, encryption: string} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['host'],
            $config['port'],
            $config['from'],
            $config['user'],
            $config['password'],
            $config['encryption']
        );
    }

    public function hasAuth(): bool
    {
        return $this->user !== '';
    }

    public function hasEncryption(): bool
    {
        return $this->encryption !== '';
    }
}
