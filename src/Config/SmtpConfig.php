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

    public function hasAuth(): bool
    {
        return $this->user !== '';
    }

    public function hasEncryption(): bool
    {
        return $this->encryption !== '';
    }
}
