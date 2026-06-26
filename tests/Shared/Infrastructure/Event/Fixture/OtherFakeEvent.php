<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Event\Fixture;

/**
 * A second, unrelated in-test event. Used to prove that a listener mapped to
 * FakeEvent does not fire for an instance of a different class, and that an
 * unmapped event is still returned unchanged by the dispatcher.
 */
final class OtherFakeEvent
{
}
