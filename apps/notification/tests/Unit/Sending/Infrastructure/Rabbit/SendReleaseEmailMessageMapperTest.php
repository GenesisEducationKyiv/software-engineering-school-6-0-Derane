<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Rabbit;

use App\Sending\Infrastructure\Rabbit\MalformedReleaseEmailMessageException;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use PHPUnit\Framework\TestCase;

final class SendReleaseEmailMessageMapperTest extends TestCase
{
    private const VALID_JSON = <<<'JSON'
    {
      "schema": "SendReleaseEmail/v1",
      "eventId": "11111111-1111-4111-8111-111111111111",
      "occurredAt": "2026-06-07T12:00:00+00:00",
      "subscriptionId": 42,
      "email": "subscriber@example.com",
      "repository": "owner/repo",
      "release": {
        "tagName": "v1.2.3",
        "name": "Release name",
        "htmlUrl": "https://github.com/owner/repo/releases/tag/v1.2.3",
        "publishedAt": "2026-06-07T11:00:00+00:00"
      }
    }
    JSON;

    private SendReleaseEmailMessageMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new SendReleaseEmailMessageMapper();
    }

    public function testMapsWellFormedJsonToReleaseEmail(): void
    {
        $email = $this->mapper->fromJson(self::VALID_JSON);

        self::assertSame(42, $email->subscriptionId);
        self::assertSame('subscriber@example.com', $email->recipientEmail);
        self::assertSame('owner/repo', $email->repository);
        self::assertSame('v1.2.3', $email->tagName);
        self::assertSame('Release name', $email->releaseName);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.2.3', $email->releaseUrl);
        self::assertSame('2026-06-07T11:00:00+00:00', $email->publishedAt);
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(MalformedReleaseEmailMessageException::class);

        $this->mapper->fromJson('{not valid json');
    }

    public function testThrowsWhenTopLevelJsonIsNotAnObject(): void
    {
        $this->expectException(MalformedReleaseEmailMessageException::class);

        $this->mapper->fromJson('[1,2,3]');
    }

    /** @return iterable<string, array{string}> */
    public static function missingFieldProvider(): iterable
    {
        foreach (['subscriptionId', 'email', 'repository', 'release'] as $field) {
            yield "missing {$field}" => [self::jsonWithout($field)];
        }

        foreach (['tagName', 'name', 'htmlUrl', 'publishedAt'] as $releaseField) {
            yield "missing release.{$releaseField}" => [self::jsonWithoutReleaseField($releaseField)];
        }
    }

    /** @dataProvider missingFieldProvider */
    public function testThrowsOnMissingRequiredField(string $json): void
    {
        $this->expectException(MalformedReleaseEmailMessageException::class);

        $this->mapper->fromJson($json);
    }

    /** @return iterable<string, array{string}> */
    public static function wrongTypedFieldProvider(): iterable
    {
        yield 'subscriptionId as string' => [self::jsonWith(['subscriptionId' => '42'])];
        yield 'email as number' => [self::jsonWith(['email' => 123])];
        yield 'repository as number' => [self::jsonWith(['repository' => 123])];
        yield 'release as string' => [self::jsonWith(['release' => 'not-an-object'])];
        yield 'release.tagName as null' => [self::jsonWithReleaseField('tagName', null)];
        yield 'release.name as number' => [self::jsonWithReleaseField('name', 1)];
        yield 'release.htmlUrl as number' => [self::jsonWithReleaseField('htmlUrl', 1)];
        yield 'release.publishedAt as number' => [self::jsonWithReleaseField('publishedAt', 1)];
    }

    /** @dataProvider wrongTypedFieldProvider */
    public function testThrowsOnWrongTypedField(string $json): void
    {
        $this->expectException(MalformedReleaseEmailMessageException::class);

        $this->mapper->fromJson($json);
    }

    /** @return array<string, mixed> */
    private static function validPayload(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(self::VALID_JSON, true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @param array<string, mixed> $overrides */
    private static function jsonWith(array $overrides): string
    {
        return json_encode(array_replace(self::validPayload(), $overrides), JSON_THROW_ON_ERROR);
    }

    private static function jsonWithout(string $field): string
    {
        $payload = self::validPayload();
        unset($payload[$field]);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private static function jsonWithReleaseField(string $field, mixed $value): string
    {
        $payload = self::validPayload();
        /** @var array<string, mixed> $release */
        $release = $payload['release'];
        $release[$field] = $value;
        $payload['release'] = $release;

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private static function jsonWithoutReleaseField(string $field): string
    {
        $payload = self::validPayload();
        /** @var array<string, mixed> $release */
        $release = $payload['release'];
        unset($release[$field]);
        $payload['release'] = $release;

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
