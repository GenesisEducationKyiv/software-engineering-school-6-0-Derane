<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Shared\Domain\Bus\Query\Response;

/**
 * The `status` field is carried internally from HW9 / Epic B onward (reconstituted
 * from the row), but is NOT yet serialized onto the JSON/gRPC wire — that is an
 * additive change in Epic E (E2/E3). The wire shape this epic stays
 * {id, email, repository, created_at}.
 *
 * @psalm-api
 */
final readonly class SubscriptionResponse implements Response
{
    public function __construct(
        public int $id,
        public string $email,
        public string $repository,
        public string $createdAt,
        public string $status
    ) {
    }
}
