<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Logging;

use App\Sending\Infrastructure\Logging\StderrLogger;
use PHPUnit\Framework\TestCase;

final class StderrLoggerTest extends TestCase
{
    /** @return resource */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }

    public function testWritesOneStructuredJsonRecordPerLine(): void
    {
        $stream = $this->memoryStream();

        (new StderrLogger($stream))->warning('Release email failed', ['repository' => 'owner/repo']);

        $line = $this->contents($stream);
        self::assertStringEndsWith("\n", $line);

        $record = json_decode(trim($line), true);
        self::assertIsArray($record);
        self::assertSame('warning', $record['level']);
        self::assertSame('notification', $record['service']);
        self::assertSame('Release email failed', $record['message']);
        self::assertSame(['repository' => 'owner/repo'], $record['context']);
        self::assertArrayHasKey('timestamp', $record);
    }

    public function testEmitsAnEmptyContextWhenNoContextIsGiven(): void
    {
        $stream = $this->memoryStream();

        (new StderrLogger($stream))->info('Notification consumer started');

        $record = json_decode(trim($this->contents($stream)), true);
        self::assertIsArray($record);
        self::assertSame('info', $record['level']);
        self::assertSame('Notification consumer started', $record['message']);
        self::assertSame([], $record['context']);
    }

    public function testRedactsSensitiveContextKeysRecursively(): void
    {
        $stream = $this->memoryStream();

        (new StderrLogger($stream))->error('SMTP failure', [
            'repository' => 'owner/repo',
            'smtp_password' => 'smtp-secret',
            'nested' => [
                'apiKey' => 'client-secret',
                'authorization' => 'Bearer token',
            ],
        ]);

        $record = json_decode(trim($this->contents($stream)), true);
        self::assertIsArray($record);
        self::assertSame([
            'repository' => 'owner/repo',
            'smtp_password' => '[redacted]',
            'nested' => [
                'apiKey' => '[redacted]',
                'authorization' => '[redacted]',
            ],
        ], $record['context']);
    }
}
