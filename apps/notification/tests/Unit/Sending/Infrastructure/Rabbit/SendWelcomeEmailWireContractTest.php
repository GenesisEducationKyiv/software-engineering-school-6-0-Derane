<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Infrastructure\Rabbit\MalformedWelcomeEmailMessageException;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Cross-service wire anchor — CONSUMER side of the SendWelcomeEmail/v1 contract.
 *
 * Asserts the mapper accepts the SAME golden file the monolith producer test
 * pins its serializer output to (contracts/send-welcome-email.v1.json, at the
 * repo root). The two services share no PHP code, so this shared file is what
 * stops their independent encode/decode implementations from silently drifting.
 * The mapper must also tolerate unknown fields (additive-only evolution) and
 * reject a wrong `schema`. Mirrors SendReleaseEmailWireContractTest (Story E2).
 */
final class SendWelcomeEmailWireContractTest extends TestCase
{
    public function testMapperParsesTheSharedGoldenContractIntoAWelcomeEmail(): void
    {
        $email = (new SendWelcomeEmailMessageMapper())->fromJson($this->goldenContractJson());

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $email->sagaId);
        self::assertSame(123, $email->subscriptionId);
        self::assertSame('user@example.com', $email->recipientEmail->value());
        self::assertSame('owner/repo', $email->repository->value());
    }

    public function testMapperToleratesAnExtraUnknownField(): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->goldenContractJson(), true, flags: JSON_THROW_ON_ERROR);
        $payload['unknownFutureField'] = 'ignored-by-the-consumer';

        $email = (new SendWelcomeEmailMessageMapper())->fromJson(
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $email->sagaId);
        self::assertSame(123, $email->subscriptionId);
    }

    public function testMapperRejectsAWrongSchemaPayload(): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->goldenContractJson(), true, flags: JSON_THROW_ON_ERROR);
        $payload['schema'] = 'SendWelcomeEmail/v2';

        $this->expectException(MalformedWelcomeEmailMessageException::class);

        (new SendWelcomeEmailMessageMapper())->fromJson(
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    private function goldenContractJson(): string
    {
        $dir = __DIR__;
        while (!is_file($dir . '/contracts/send-welcome-email.v1.json')) {
            $parent = dirname($dir);
            self::assertNotSame($parent, $dir, 'contracts/send-welcome-email.v1.json not found above ' . __DIR__);
            $dir = $parent;
        }

        return (string) file_get_contents($dir . '/contracts/send-welcome-email.v1.json');
    }
}
