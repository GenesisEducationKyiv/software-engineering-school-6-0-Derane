<?php

declare(strict_types=1);

namespace App\Domain;

use JsonSerializable;

final class Subscription implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $repository,
        public readonly string $createdAt
    ) {
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'repository' => $this->repository,
            'created_at' => $this->createdAt,
        ];
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
