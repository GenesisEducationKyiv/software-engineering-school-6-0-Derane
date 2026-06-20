<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Infrastructure\Rabbit\MalformedWelcomeEmailMessageException;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailMessageMapper;
use PHPUnit\Framework\TestCase;

final class SendWelcomeEmailMessageMapperTest extends TestCase
{
    private const VALID_JSON = <<<'JSON'
    {
      "schema": "SendWelcomeEmail/v1",
      "sagaId": "5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33",
      "subscriptionId": 123,
      "email": "user@example.com",
      "repository": "owner/repo",
      "occurredAt": "2026-06-20T12:00:00+00:00"
    }
    JSON;

    private SendWelcomeEmailMessageMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new SendWelcomeEmailMessageMapper();
    }

    public function testMapsAValidV1PayloadToAWelcomeEmail(): void
    {
        $welcome = $this->mapper->fromJson(self::VALID_JSON);

        self::assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $welcome->sagaId);
        self::assertSame(123, $welcome->subscriptionId);
        self::assertSame('user@example.com', $welcome->recipientEmail->value());
        self::assertSame('owner/repo', $welcome->repository->value());
        self::assertSame(123, $welcome->key()->subscriptionId);
    }

    public function testToleratesUnknownFields(): void
    {
        $json = <<<'JSON'
        {
          "schema": "SendWelcomeEmail/v1",
          "sagaId": "s",
          "subscriptionId": 7,
          "email": "user@example.com",
          "repository": "owner/repo",
          "occurredAt": "2026-06-20T12:00:00+00:00",
          "futureField": {"nested": true},
          "anotherUnknown": 42
        }
        JSON;

        $welcome = $this->mapper->fromJson($json);

        self::assertSame(7, $welcome->subscriptionId);
    }

    public function testRejectsAnUnknownSchema(): void
    {
        $json = str_replace('SendWelcomeEmail/v1', 'SendWelcomeEmail/v2', self::VALID_JSON);

        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson($json);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson('{not valid json');
    }

    public function testRejectsAMissingRequiredField(): void
    {
        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson('{"schema":"SendWelcomeEmail/v1","sagaId":"s"}');
    }

    public function testRejectsAWrongTypedSubscriptionId(): void
    {
        $json = str_replace('"subscriptionId": 123', '"subscriptionId": "123"', self::VALID_JSON);

        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson($json);
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $json = str_replace('user@example.com', 'not-an-email', self::VALID_JSON);

        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson($json);
    }

    public function testRejectsAnInvalidRepository(): void
    {
        $json = str_replace('owner/repo', 'no-slash', self::VALID_JSON);

        $this->expectException(MalformedWelcomeEmailMessageException::class);
        $this->mapper->fromJson($json);
    }
}
