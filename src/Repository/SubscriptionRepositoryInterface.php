<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;

/** @psalm-api */
interface SubscriptionRepositoryInterface
{
    public function create(string $email, string $repository): Subscription;

    public function findById(int $id): ?Subscription;

    public function findByEmail(string $email, Pagination $pagination): SubscriptionPage;

    public function findAll(Pagination $pagination): SubscriptionPage;

    public function delete(int $id): bool;
}
