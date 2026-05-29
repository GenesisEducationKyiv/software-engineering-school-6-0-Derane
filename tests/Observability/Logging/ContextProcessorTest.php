<?php

declare(strict_types=1);

namespace Tests\Observability\Logging;

use App\Observability\CorrelationContext;
use App\Observability\Logging\ContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class ContextProcessorTest extends TestCase
{
    public function testAddsComponentAndEnvToEveryRecord(): void
    {
        $processor = new ContextProcessor(new CorrelationContext(), 'api', 'production');

        $record = $processor($this->record());

        $this->assertSame('api', $record->extra['component']);
        $this->assertSame('production', $record->extra['env']);
        $this->assertArrayNotHasKey('correlation_id', $record->extra);
    }

    public function testAddsCorrelationIdWhenPresent(): void
    {
        $context = new CorrelationContext();
        $context->start('abc123');

        $record = (new ContextProcessor($context, 'scanner', 'test'))($this->record());

        $this->assertSame('abc123', $record->extra['correlation_id']);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'test message');
    }
}
