<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/** In-house CQRS query bus — NOT Symfony Messenger. @psalm-api */
interface QueryBus
{
    public function ask(Query $query): Response;
}
