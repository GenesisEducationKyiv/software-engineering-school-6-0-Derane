<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit\Contract;

use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Infrastructure\Rabbit\SendWelcomeEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — PRODUCER side of the SendWelcomeEmail/v1 contract.
 *
 * This and the notification service's consumer contract test
 * (apps/notification/tests/.../SendWelcomeEmailWireContractTest) assert against
 * the SAME golden file, contracts/send-welcome-email.v1.json — the single source
 * of truth for the wire shape. The two services share no PHP code, so this shared
 * file is what stops their independent encode/decode implementations from
 * silently drifting. If the serializer's output diverges from the golden file,
 * this test fails. Mirrors SendReleaseEmailWireContractTest exactly (FR13
 * discipline, Story E2).
 *
 * See also Tests\Notification\Publishing\Contract\SendReleaseEmailGoldenUnchangedTest
 * (tests/Notification/Publishing/Contract/) — the FR13 guard that freezes the
 * SendReleaseEmail/v1 golden byte-for-byte while these welcome goldens are added.
 */
final class SendWelcomeEmailWireContractTest extends TestCase
{
    public function testSerializerOutputMatchesTheSharedGoldenContract(): void
    {
        $message = new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new \DateTimeImmutable('2026-06-20T12:00:00+00:00'),
        );

        // assertSame on associative arrays is order-sensitive, so this pins key
        // order and value types too, not just the set of key/value pairs.
        self::assertSame(
            $this->goldenContract(),
            (new SendWelcomeEmailSerializer())->toArray($message),
        );
    }

    /** @return array<array-key, mixed> */
    private function goldenContract(): array
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/send-welcome-email.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/send-welcome-email.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        $decoded = json_decode(
            (string) file_get_contents($dir . '/contracts/send-welcome-email.v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }
}
