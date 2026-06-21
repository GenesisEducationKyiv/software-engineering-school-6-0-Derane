<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Contract;

use PHPUnit\Framework\TestCase;

/**
 * FR13 guard (Story E2): the release contract is untouched by the welcome-saga
 * work. The two new goldens (send-welcome-email.v1.json, welcome-email-outcome.v1.json)
 * are ADDITIVE — they must not perturb the frozen SendReleaseEmail/v1 wire shape.
 *
 * This pins the release golden byte-for-byte (sha256 over the raw file bytes), so
 * any edit — even a whitespace or key-order change a structural assertion would
 * miss — fails loudly. If a legitimate SendReleaseEmail/v2 is ever introduced it
 * is a NEW file; v1 stays frozen.
 */
final class SendReleaseEmailGoldenUnchangedTest extends TestCase
{
    private const GOLDEN_SHA256 = '86e79c15601649ca23a31a9e89e05a4d6e14fde75d88f02d77b8655bdf613197';

    public function testReleaseGoldenIsByteUnchanged(): void
    {
        $path = $this->goldenPath();

        self::assertSame(
            self::GOLDEN_SHA256,
            hash('sha256', (string) file_get_contents($path)),
            'contracts/send-release-email.v1.json changed — the SendReleaseEmail/v1 wire shape is '
            . 'frozen (FR13). Introduce a SendReleaseEmail/v2 file instead of mutating v1.'
        );
    }

    public function testReleaseGoldenStillDecodesToTheExpectedShape(): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) file_get_contents($this->goldenPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('SendReleaseEmail/v1', $decoded['schema']);
        self::assertSame(
            ['schema', 'eventId', 'occurredAt', 'subscriptionId', 'email', 'repository', 'release'],
            array_keys($decoded),
        );
    }

    private function goldenPath(): string
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/send-release-email.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/send-release-email.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        return $dir . '/contracts/send-release-email.v1.json';
    }
}
