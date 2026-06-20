<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit\Contract;

use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Infrastructure\Rabbit\MalformedWelcomeEmailOutcomeException;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — CONSUMER side of the WelcomeEmailOutcome/v1 contract.
 *
 * The monolith orchestrator consumes the notification service's reply. This test
 * asserts the mapper parses the SAME golden file the notification producer test
 * pins its publisher payload to (contracts/welcome-email-outcome.v1.json). The two
 * services share no PHP code, so this shared file is what stops their independent
 * encode/decode implementations from silently drifting. The mapper must also
 * tolerate unknown fields (additive-only evolution) and reject a wrong `schema`.
 * Mirrors the SendReleaseEmail consumer-contract discipline (Story E2).
 */
final class WelcomeEmailOutcomeWireContractTest extends TestCase
{
    public function testMapperParsesTheSharedGoldenContract(): void
    {
        $command = (new WelcomeEmailOutcomeMessageMapper())->fromJson($this->goldenContractJson());

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $command->sagaId);
        self::assertSame(123, $command->subscriptionId);
        self::assertSame(WelcomeOutcome::Sent, $command->outcome);
    }

    public function testMapperToleratesAnExtraUnknownField(): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->goldenContractJson(), true, flags: JSON_THROW_ON_ERROR);
        $payload['unknownFutureField'] = 'ignored-by-the-consumer';

        $command = (new WelcomeEmailOutcomeMessageMapper())->fromJson(
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $command->sagaId);
        self::assertSame(WelcomeOutcome::Sent, $command->outcome);
    }

    /**
     * Symmetry guard: arch §7 says a `sent` reply nulls `error`, and the single
     * internal producer enforces that. But `error` is a non-required field the
     * consumer never reads, so a non-conformant producer that put a stray non-null
     * `error` on a `sent` reply must NOT poison the orchestrator — the mapper still
     * yields a clean Sent outcome. This pins that tolerance so a future "validate
     * error on sent" change can't silently turn a tolerated field into a hard reject.
     */
    public function testMapperIgnoresAStrayErrorOnASentReply(): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->goldenContractJson(), true, flags: JSON_THROW_ON_ERROR);
        $payload['error'] = 'this should be null on a sent reply, but the consumer must tolerate it';

        $command = (new WelcomeEmailOutcomeMessageMapper())->fromJson(
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $command->sagaId);
        self::assertSame(123, $command->subscriptionId);
        self::assertSame(WelcomeOutcome::Sent, $command->outcome);
    }

    public function testMapperRejectsAWrongSchemaPayload(): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->goldenContractJson(), true, flags: JSON_THROW_ON_ERROR);
        $payload['schema'] = 'WelcomeEmailOutcome/v2';

        $this->expectException(MalformedWelcomeEmailOutcomeException::class);

        (new WelcomeEmailOutcomeMessageMapper())->fromJson(
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    private function goldenContractJson(): string
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/welcome-email-outcome.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/welcome-email-outcome.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        return (string) file_get_contents($dir . '/contracts/welcome-email-outcome.v1.json');
    }
}
