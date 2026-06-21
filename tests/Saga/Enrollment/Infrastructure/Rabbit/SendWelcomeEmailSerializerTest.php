<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Infrastructure\Rabbit\SendWelcomeEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

/**
 * The SendWelcomeEmail/v1 wire shape is a public contract (arch §7 /
 * contracts/send-welcome-email.v1.json). These tests pin every field, the schema
 * spelling, and the RFC3339 occurredAt formatting.
 */
final class SendWelcomeEmailSerializerTest extends TestCase
{
    public function testToArrayShapesEveryWireField(): void
    {
        $array = (new SendWelcomeEmailSerializer())->toArray($this->message());

        self::assertSame([
            'schema' => 'SendWelcomeEmail/v1',
            'sagaId' => '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            'subscriptionId' => 123,
            'email' => 'user@example.com',
            'repository' => 'owner/repo',
            'occurredAt' => '2026-06-20T12:00:00+00:00',
        ], $array);
    }

    public function testToJsonIsDecodableAndCarriesTheSchema(): void
    {
        $json = (new SendWelcomeEmailSerializer())->toJson($this->message());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('SendWelcomeEmail/v1', $decoded['schema']);
        self::assertSame(123, $decoded['subscriptionId']);
        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $decoded['sagaId']);
    }

    public function testOccurredAtIsFormattedAsRfc3339WithoutFractionalSeconds(): void
    {
        $array = (new SendWelcomeEmailSerializer())->toArray($this->message());

        self::assertSame('2026-06-20T12:00:00+00:00', $array['occurredAt']);
    }

    private function message(): SendWelcomeEmail
    {
        return new SendWelcomeEmail(
            SendWelcomeEmail::SCHEMA,
            '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33',
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new \DateTimeImmutable('2026-06-20T12:00:00+00:00'),
        );
    }
}
