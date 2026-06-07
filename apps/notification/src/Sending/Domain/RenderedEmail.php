<?php

declare(strict_types=1);

namespace App\Sending\Domain;

final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $htmlBody,
        public string $textBody,
    ) {
    }
}
