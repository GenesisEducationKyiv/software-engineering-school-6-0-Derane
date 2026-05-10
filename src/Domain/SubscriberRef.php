<?php

declare(strict_types=1);

namespace App\Domain;

final class SubscriberRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $email
    ) {
    }
}
