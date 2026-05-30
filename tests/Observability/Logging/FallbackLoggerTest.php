<?php

declare(strict_types=1);

namespace Tests\Observability\Logging;

use App\Observability\Logging\EmailMasker;
use App\Observability\Logging\FallbackLogger;
use PHPUnit\Framework\TestCase;

class FallbackLoggerTest extends TestCase
{
    public function testFormatsAStructuredJsonLineWithStableFields(): void
    {
        $logger = new FallbackLogger('scanner', 'test', new EmailMasker());

        $json = $logger->format('TestEvent', new \RuntimeException('boom'));

        $this->assertStringContainsString('"component":"scanner"', $json);
        $this->assertStringContainsString('"env":"test"', $json);
        $this->assertStringContainsString('"event":"observability.listener_failed"', $json);
        $this->assertStringContainsString('"listener_event_class":"TestEvent"', $json);
        $this->assertStringContainsString('"error_class":"RuntimeException"', $json);
        $this->assertStringContainsString('"error":"boom"', $json);
        // Filebeat keeps only records carrying extra.component — assert the nesting.
        $this->assertStringContainsString('"extra":{"component":"scanner","env":"test"}', $json);
    }

    public function testMasksEmailsEmbeddedInTheErrorMessage(): void
    {
        $logger = new FallbackLogger('api', 'test', new EmailMasker());

        $json = $logger->format('X', new \RuntimeException('could not mail user@example.com'));

        $this->assertStringContainsString('"error":"could not mail u***@example.com"', $json);
    }
}
