<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Infrastructure\Mail;

final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $htmlBody,
        public string $textBody
    ) {
    }
}
