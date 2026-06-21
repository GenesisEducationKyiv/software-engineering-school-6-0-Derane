<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Infrastructure\Rabbit\WelcomeOutcomeSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — PRODUCER side of the WelcomeEmailOutcome/v1 contract.
 *
 * The notification service is the producer of the reply; the monolith orchestrator
 * is the consumer. This test pins the body that {@see WelcomeOutcomeSerializer}
 * (extracted from {@see \App\Sending\Infrastructure\Rabbit\RabbitWelcomeOutcomePublisher}
 * so it is testable without a broker) emits against the SAME golden file the
 * monolith consumer test reads (contracts/welcome-email-outcome.v1.json). The two
 * services share no PHP code, so this shared file is what stops their independent
 * encode/decode implementations from silently drifting. Mirrors
 * SendReleaseEmailWireContractTest (Story E2).
 */
final class WelcomeEmailOutcomeWireContractTest extends TestCase
{
    public function testSerializerOutputMatchesTheSharedGoldenContract(): void
    {
        // The golden carries outcome=sent, so error is null (the two-state invariant).
        $payload = (new WelcomeOutcomeSerializer())->toArray(
            sagaId: '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            subscriptionId: 123,
            outcome: WelcomeOutcome::Sent,
            error: null,
            occurredAt: new \DateTimeImmutable('2026-06-20T12:00:03+00:00'),
        );

        // assertSame on associative arrays is order-sensitive, so this pins key
        // order and value types too, not just the set of key/value pairs.
        self::assertSame($this->goldenContract(), $payload);
    }

    public function testFailedOutcomeCarriesTheErrorStringAdditively(): void
    {
        // The golden file is the `sent` exemplar; the `failed` variant shares the
        // exact key set, only `outcome` + `error` differ — proven here so the
        // golden need not be duplicated for the second enum value.
        $payload = (new WelcomeOutcomeSerializer())->toArray(
            sagaId: '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            subscriptionId: 123,
            outcome: WelcomeOutcome::Failed,
            error: 'smtp: mailbox unavailable',
            occurredAt: new \DateTimeImmutable('2026-06-20T12:00:03+00:00'),
        );

        self::assertSame(array_keys($this->goldenContract()), array_keys($payload));
        self::assertSame('failed', $payload['outcome']);
        self::assertSame('smtp: mailbox unavailable', $payload['error']);
    }

    public function testSentOutcomeNullsAnyErrorString(): void
    {
        $payload = (new WelcomeOutcomeSerializer())->toArray(
            sagaId: '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            subscriptionId: 123,
            outcome: WelcomeOutcome::Sent,
            error: 'should be dropped on a sent reply',
            occurredAt: new \DateTimeImmutable('2026-06-20T12:00:03+00:00'),
        );

        self::assertNull($payload['error']);
    }

    /** @return array<array-key, mixed> */
    private function goldenContract(): array
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/welcome-email-outcome.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/welcome-email-outcome.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        $decoded = json_decode(
            (string) file_get_contents($dir . '/contracts/welcome-email-outcome.v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }
}
