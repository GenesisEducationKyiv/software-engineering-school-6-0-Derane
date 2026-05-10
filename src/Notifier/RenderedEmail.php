<?php

declare(strict_types=1);

namespace App\Notifier;

final class RenderedEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody
    ) {
    }
}
