<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

/**
 * This service's own SMTP-config VO — recreated, not imported, from the
 * monolith's `App\Config\SmtpConfig` (cross-deployable boundary: `apps/notification`
 * has its own composer autoload root and cannot `use` a monolith class, exactly
 * like D3 could not import `App\Scanning\...\RenderedEmail` and instead
 * promoted/recreated it). Identical shape (`host, port, from, user, password,
 * encryption` + `hasAuth()`/`hasEncryption()`) — `PhpMailerMailer` depends on
 * both predicates to drive `SmtpMailer`'s proven conditional-auth/encryption
 * sequence (Technical Decisions §4).
 */
final readonly class SmtpConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $from,
        public string $user,
        public string $password,
        public string $encryption,
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
