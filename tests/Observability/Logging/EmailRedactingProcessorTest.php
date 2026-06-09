<?php

declare(strict_types=1);

namespace Tests\Observability\Logging;

use App\Observability\Logging\EmailMasker;
use App\Observability\Logging\EmailRedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class EmailRedactingProcessorTest extends TestCase
{
    public function testMasksEmailEmbeddedInTheMessage(): void
    {
        $record = $this->process('Unhandled exception: could not mail john@example.com');

        $this->assertSame('Unhandled exception: could not mail j***@example.com', $record->message);
    }

    public function testMasksEmailsInContextAndExtraIncludingNestedAndLeavesNonStringsAlone(): void
    {
        $record = $this->process(
            'msg',
            ['email' => 'john@example.com', 'nested' => ['to' => 'jane@corp.io'], 'count' => 3],
            ['component' => 'api']
        );

        $this->assertSame(
            ['email' => 'j***@example.com', 'nested' => ['to' => 'j***@corp.io'], 'count' => 3],
            $record->context
        );
        $this->assertSame(['component' => 'api'], $record->extra);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function process(string $message, array $context = [], array $extra = []): LogRecord
    {
        $record = new LogRecord(
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            'api',
            Level::Error,
            $message,
            $context,
            $extra
        );

        return (new EmailRedactingProcessor(new EmailMasker()))($record);
    }
}
