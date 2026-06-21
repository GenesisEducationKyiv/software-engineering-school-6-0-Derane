<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Infrastructure\Rabbit\MalformedWelcomeEmailOutcomeException;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper is the anti-corruption boundary for the WelcomeEmailOutcome/v1 reply:
 * it accepts a well-formed reply (tolerating unknown fields) and rejects every
 * poison shape to MalformedWelcomeEmailOutcomeException (the consumer then ack-drops
 * it — the reply queue has no DLX, arch §7).
 */
final class WelcomeEmailOutcomeMessageMapperTest extends TestCase
{
    private const SAGA_ID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    public function testMapsAWellFormedSentReply(): void
    {
        $command = $this->mapper()->fromJson($this->body('sent'));

        self::assertSame(self::SAGA_ID, $command->sagaId);
        self::assertSame(123, $command->subscriptionId);
        self::assertSame(WelcomeOutcome::Sent, $command->outcome);
    }

    public function testMapsAWellFormedFailedReply(): void
    {
        $command = $this->mapper()->fromJson($this->body('failed', error: 'smtp down'));

        self::assertSame(WelcomeOutcome::Failed, $command->outcome);
    }

    public function testToleratesUnknownFields(): void
    {
        $json = json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => 'sent',
            'error' => null,
            'occurredAt' => '2026-06-20T12:00:03+00:00',
            'futureField' => 'ignored',
        ], JSON_THROW_ON_ERROR);

        $command = $this->mapper()->fromJson($json);

        self::assertSame(WelcomeOutcome::Sent, $command->outcome);
    }

    public function testRejectsUnknownSchema(): void
    {
        $this->expectException(MalformedWelcomeEmailOutcomeException::class);
        $this->mapper()->fromJson(json_encode([
            'schema' => 'WelcomeEmailOutcome/v2',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => 'sent',
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsUndecodableJson(): void
    {
        $this->expectException(MalformedWelcomeEmailOutcomeException::class);
        $this->mapper()->fromJson('{not json');
    }

    public function testRejectsAnInvalidSagaId(): void
    {
        $this->expectException(MalformedWelcomeEmailOutcomeException::class);
        $this->mapper()->fromJson(json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => 'not-a-uuid',
            'subscriptionId' => 123,
            'outcome' => 'sent',
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsAnUnknownOutcomeValue(): void
    {
        $this->expectException(MalformedWelcomeEmailOutcomeException::class);
        $this->mapper()->fromJson(json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => 'maybe',
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsAMissingOrWrongTypeSubscriptionId(): void
    {
        $this->expectException(MalformedWelcomeEmailOutcomeException::class);
        $this->mapper()->fromJson(json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => '123',
            'outcome' => 'sent',
        ], JSON_THROW_ON_ERROR));
    }

    private function body(string $outcome, ?string $error = null): string
    {
        return json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => $outcome,
            'error' => $error,
            'occurredAt' => '2026-06-20T12:00:03+00:00',
        ], JSON_THROW_ON_ERROR);
    }

    private function mapper(): WelcomeEmailOutcomeMessageMapper
    {
        return new WelcomeEmailOutcomeMessageMapper();
    }
}
