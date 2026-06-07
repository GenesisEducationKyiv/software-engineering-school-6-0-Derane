<?php

declare(strict_types=1);

namespace Tests\Notification\Publishing\Domain;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use PHPUnit\Framework\TestCase;

final class ReleaseNotificationPublisherTest extends TestCase
{
    public function testIsAPureDomainInterfaceWithASinglePublishMethod(): void
    {
        $reflection = new \ReflectionClass(ReleaseNotificationPublisher::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertSame('App\Notification\Publishing\Domain', $reflection->getNamespaceName());
        $this->assertTrue($reflection->hasMethod('publish'));

        $publish = $reflection->getMethod('publish');
        $this->assertTrue($publish->hasReturnType());
        $this->assertSame('void', (string) $publish->getReturnType());

        $parameters = $publish->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame(SendReleaseEmail::class, (string) $parameters[0]->getType());
    }

    public function testCanBeImplementedByAnAdapterThatPublishesTheMessage(): void
    {
        $publisher = new class implements ReleaseNotificationPublisher {
            public ?SendReleaseEmail $published = null;

            #[\Override]
            public function publish(SendReleaseEmail $message): void
            {
                $this->published = $message;
            }
        };

        $message = $this->buildMessage();
        $publisher->publish($message);

        $this->assertSame($message, $publisher->published);
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
                'Release name',
                'https://github.com/owner/repo/releases/tag/v1.2.3',
                '2026-06-07T11:00:00+00:00'
            )
        );
    }
}
