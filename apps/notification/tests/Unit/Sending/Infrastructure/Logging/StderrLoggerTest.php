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

    public function testWritesLevelMessageAndJsonContextOnOneLine(): void
    {
        $stream = $this->memoryStream();

        (new StderrLogger($stream))->warning('Release email failed', ['repository' => 'owner/repo']);

        $line = $this->contents($stream);
        self::assertStringContainsString('notification.warning:', $line);
        self::assertStringContainsString('Release email failed', $line);
        self::assertStringContainsString('{"repository":"owner\/repo"}', $line);
        self::assertStringEndsWith("\n", $line);
    }

    public function testOmitsContextSuffixWhenContextIsEmpty(): void
    {
        $stream = $this->memoryStream();

        (new StderrLogger($stream))->info('Notification consumer started');

        $line = $this->contents($stream);
        self::assertStringContainsString('notification.info: Notification consumer started', $line);
        self::assertStringNotContainsString('{', $line);
    }
}
