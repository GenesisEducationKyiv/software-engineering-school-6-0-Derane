<?php

declare(strict_types=1);

namespace Tests\Observability\Logging;

use App\Observability\Logging\EmailMasker;
use PHPUnit\Framework\TestCase;

class EmailMaskerTest extends TestCase
{
    public function testKeepsFirstLocalCharAndDomain(): void
    {
        $this->assertSame('u***@example.com', (new EmailMasker())->mask('user@example.com'));
    }

    public function testMasksFullyWhenThereIsNoAtSign(): void
    {
        $this->assertSame('***', (new EmailMasker())->mask('not-an-email'));
    }

    public function testMasksFullyWhenLocalPartIsEmpty(): void
    {
        $this->assertSame('***', (new EmailMasker())->mask('@example.com'));
    }

    public function testMasksFullyWhenDomainIsEmpty(): void
    {
        $this->assertSame('***', (new EmailMasker())->mask('user@'));
    }

    public function testMaskEmailsInReplacesEmbeddedAddresses(): void
    {
        $this->assertSame(
            'Invalid address: j***@example.com (rejected)',
            (new EmailMasker())->maskEmailsIn('Invalid address: john@example.com (rejected)')
        );
    }

    public function testMaskEmailsInLeavesTextWithoutEmailsUnchanged(): void
    {
        $this->assertSame('connection refused', (new EmailMasker())->maskEmailsIn('connection refused'));
    }
}
