<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Infrastructure\Serialization;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

/**
 * THE CONTRACT TEST (story C1, AC2/AC3, Decision 5): pins the SendReleaseEmail/v1
 * wire shape byte-for-byte against architecture §7's literal JSON example.
 *
 * Four downstream stories (C5, D3, D4 and beyond) build on this shape staying
 * stable — any change to key names, nesting, or value formats here is a
 * BREAKING wire-format change and must be a deliberate, reviewed decision
 * (additive-only versioning per §7: "SendReleaseEmail/v1").
 */
final class SendReleaseEmailSerializerTest extends TestCase
{
    public function testSerializesToWireSchemaV1ExactShape(): void
    {
        $serializer = new SendReleaseEmailSerializer();
        $message = new SendReleaseEmail(
            'SendReleaseEmail/v1',
            '11111111-2222-4333-8444-555555555555',
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00'),
            123,
            new EmailAddress('user@example.com'),
            new RepositoryName('owner/repo'),
            new ReleaseSnapshot(
                new ReleaseTag('v1.2.3'),
                'Release v1.2.3',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                new \DateTimeImmutable('2026-06-07T11:00:00+00:00')->format(\DateTimeInterface::RFC3339)
            )
        );

        $expectedJson = <<<'JSON'
            {
                "schema": "SendReleaseEmail/v1",
                "eventId": "11111111-2222-4333-8444-555555555555",
                "occurredAt": "2026-06-07T12:00:00+00:00",
                "subscriptionId": 123,
                "email": "user@example.com",
                "repository": "owner/repo",
                "release": {
                    "tagName": "v1.2.3",
                    "name": "Release v1.2.3",
                    "htmlUrl": "https://github.com/owner/repo/releases/tag/v1.2.3",
                    "publishedAt": "2026-06-07T11:00:00+00:00"
                }
            }
            JSON;

        $this->assertJsonStringEqualsJsonString($expectedJson, $serializer->toJson($message));
    }

    public function testToArrayHasExactlyTheTopLevelKeysFromArchitectureSection7(): void
    {
        $serializer = new SendReleaseEmailSerializer();

        $array = $serializer->toArray($this->buildMessage());

        $this->assertSame(
            ['schema', 'eventId', 'occurredAt', 'subscriptionId', 'email', 'repository', 'release'],
            array_keys($array)
        );
        $this->assertSame(
            ['tagName', 'name', 'htmlUrl', 'publishedAt'],
            array_keys($array['release'])
        );
    }

    public function testOccurredAtSerializesAsAnRfc3339String(): void
    {
        $serializer = new SendReleaseEmailSerializer();

        $array = $serializer->toArray($this->buildMessage());

        $this->assertSame('2026-06-07T12:00:00+00:00', $array['occurredAt']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $array['occurredAt']
        );
    }

    public function testReleasePublishedAtSerializesAsAnRfc3339String(): void
    {
        $serializer = new SendReleaseEmailSerializer();

        $array = $serializer->toArray($this->buildMessage());

        $this->assertSame('2026-06-07T11:00:00+00:00', $array['release']['publishedAt']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $array['release']['publishedAt']
        );
    }

    public function testUnwrapsValueObjectsToTheirPlainStringWireRepresentation(): void
    {
        $serializer = new SendReleaseEmailSerializer();

        $array = $serializer->toArray($this->buildMessage());

        $this->assertSame('user@example.com', $array['email']);
        $this->assertSame('owner/repo', $array['repository']);
        $this->assertSame('v1.2.3', $array['release']['tagName']);
        $this->assertIsString($array['email']);
        $this->assertIsString($array['repository']);
        $this->assertIsString($array['release']['tagName']);
    }

    private function buildMessage(): SendReleaseEmail
    {
        return new SendReleaseEmail(
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
                '2026-06-07T11:00:00+00:00'
            )
        );
    }
}
