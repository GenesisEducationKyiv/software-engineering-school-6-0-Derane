<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\NotificationLedger;
use PHPUnit\Framework\TestCase;

final class NotificationLedgerTest extends TestCase
{
    public function testInterfaceIsImplementable(): void
    {
        $ledger = $this->createMock(NotificationLedger::class);
        self::assertInstanceOf(NotificationLedger::class, $ledger);
    }
}
