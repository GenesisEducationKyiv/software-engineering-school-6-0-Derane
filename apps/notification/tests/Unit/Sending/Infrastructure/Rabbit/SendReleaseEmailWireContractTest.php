<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — CONSUMER side of the SendReleaseEmail/v1 contract.
 *
 * Asserts the mapper accepts the SAME golden file the monolith producer test
 * pins its serializer output to (contracts/send-release-email.v1.json, at the
 * repo root). The two services share no PHP code, so this shared file is what
 * stops their independent encode/decode implementations from silently drifting.
 * If someone edits the golden file, this test fails unless the consumer agrees.
 */
final class SendReleaseEmailWireContractTest extends TestCase
{
    public function testMapperParsesTheSharedGoldenContractIntoAReleaseEmail(): void
    {
        $email = (new SendReleaseEmailMessageMapper())->fromJson($this->goldenContractJson());

        self::assertSame('11111111-2222-4333-8444-555555555555', $email->eventId);
        self::assertSame(123, $email->subscriptionId);
        self::assertSame('user@example.com', $email->recipientEmail->value());
        self::assertSame('owner/repo', $email->repository->value());
        self::assertSame('v1.2.3', $email->tagName->value());
        self::assertSame('Release v1.2.3', $email->releaseName);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $email->releaseUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $email->publishedAt);
        self::assertSame('Release body text.', $email->releaseBody);
    }

    private function goldenContractJson(): string
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/send-release-email.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/send-release-email.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        return (string) file_get_contents($dir . '/contracts/send-release-email.v1.json');
    }
}
