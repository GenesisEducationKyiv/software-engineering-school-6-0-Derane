<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Event\Fixture;

/**
 * Plain in-test event: a bare identity marker with no payload. Dispatching it
 * exercises the dispatcher against an arbitrary `object` (the PSR-14 contract),
 * not a DomainEvent — A3's plane is intentionally decoupled from A2.
 */
final class FakeEvent
{
}
