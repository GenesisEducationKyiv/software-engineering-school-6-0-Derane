<?php

declare(strict_types=1);

namespace App\Config;

final readonly class SmtpConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $from,
        public string $user,
        public string $password,
        public string $encryption
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
