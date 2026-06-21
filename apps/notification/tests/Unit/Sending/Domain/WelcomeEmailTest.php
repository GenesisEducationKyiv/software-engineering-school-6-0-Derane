<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\RenderableEmail;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Domain\WelcomeNotificationKey;
use PHPUnit\Framework\TestCase;

final class WelcomeEmailTest extends TestCase
{
    public function testKeyIsDerivedFromSubscriptionIdAlone(): void
    {
        $email = new WelcomeEmail(
            sagaId: 's-1',
            subscriptionId: 99,
            recipientEmail: new EmailAddress('a@b.c'),
            repository: new RepositoryName('owner/repo'),
        );

        $key = $email->key();

        self::assertInstanceOf(WelcomeNotificationKey::class, $key);
        self::assertSame(99, $key->subscriptionId);
    }

    public function testIsARenderableEmail(): void
    {
        $email = new WelcomeEmail(
            sagaId: 's-1',
            subscriptionId: 1,
            recipientEmail: new EmailAddress('a@b.c'),
            repository: new RepositoryName('owner/repo'),
        );

        self::assertInstanceOf(RenderableEmail::class, $email);
    }
}
