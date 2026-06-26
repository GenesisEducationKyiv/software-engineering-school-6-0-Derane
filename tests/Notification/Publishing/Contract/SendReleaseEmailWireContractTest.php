<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Contract;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — PRODUCER side of the SendReleaseEmail/v1 contract.
 *
 * This and the notification service's consumer contract test
 * (apps/notification/tests/.../SendReleaseEmailWireContractTest) assert against
 * the SAME golden file, contracts/send-release-email.v1.json — the single source
 * of truth for the wire shape. The two services share no PHP code, so this shared
 * file is what stops their independent encode/decode implementations from
 * silently drifting. If the serializer's output diverges from the golden file,
 * this test fails.
 */
final class SendReleaseEmailWireContractTest extends TestCase
{
    public function testSerializerOutputMatchesTheSharedGoldenContract(): void
    {
        $message = new SendReleaseEmail(
            SendReleaseEmail::SCHEMA,
            '11111111-2222-4333-8444-555555555555',
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release v1.2.3',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                '2026-06-07T11:00:00+00:00',
                'Release body text.',
            )
        );

        // assertSame on associative arrays is order-sensitive, so this pins key
        // order and value types too, not just the set of key/value pairs.
        self::assertSame(
            $this->goldenContract(),
            (new SendReleaseEmailSerializer())->toArray($message)
        );
    }

    /** @return array<array-key, mixed> */
    private function goldenContract(): array
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/send-release-email.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/send-release-email.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        $decoded = json_decode(
            (string) file_get_contents($dir . '/contracts/send-release-email.v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertIsArray($decoded);

        return $decoded;
    }
}
