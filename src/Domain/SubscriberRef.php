<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class SubscriberRef
{
    public function __construct(
        public int $id,
        public string $email
    ) {
    }
}
