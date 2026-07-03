<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Sync;

use App\Sending\Domain\WelcomeEmail;
use App\Sending\Infrastructure\Error\WelcomeRequestValidationException;
use App\Sending\Infrastructure\Sync\WelcomeEmailFactory;
use Notification\Welcome\V1\SendWelcomeEmailRequest;
use PHPUnit\Framework\TestCase;

final class WelcomeEmailFactoryTest extends TestCase
{
    private WelcomeEmailFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->factory = new WelcomeEmailFactory();
    }

    public function testFromGrpcBuildsWelcomeEmailFromValidRequest(): void
    {
        $request = new SendWelcomeEmailRequest([
            'saga_id' => '5f1c0e2a-1111-2222-3333-444455556666',
            'subscription_id' => 123,
            'email' => 'user@example.com',
            'repository' => 'owner/repo',
        ]);

        $email = $this->factory->fromGrpc($request);

        self::assertInstanceOf(WelcomeEmail::class, $email);
        self::assertSame('5f1c0e2a-1111-2222-3333-444455556666', $email->sagaId);
        self::assertSame(123, $email->subscriptionId);
        self::assertSame('user@example.com', $email->recipientEmail->value());
        self::assertSame('owner/repo', $email->repository->value());
    }

    public function testFromGrpcThrowsValidationOnMalformedEmail(): void
    {
        $request = new SendWelcomeEmailRequest([
            'saga_id' => 'saga-1',
            'subscription_id' => 1,
            'email' => 'not-an-email',
            'repository' => 'owner/repo',
        ]);

        $this->expectException(WelcomeRequestValidationException::class);

        $this->factory->fromGrpc($request);
    }

    public function testFromGrpcThrowsValidationOnMalformedRepository(): void
    {
        $request = new SendWelcomeEmailRequest([
            'saga_id' => 'saga-1',
            'subscription_id' => 1,
            'email' => 'user@example.com',
            'repository' => 'no-slash',
        ]);

        $this->expectException(WelcomeRequestValidationException::class);

        $this->factory->fromGrpc($request);
    }

    public function testFromArrayBuildsWelcomeEmailFromValidPayload(): void
    {
        $email = $this->factory->fromArray([
            'sagaId' => 'saga-7',
            'subscriptionId' => 99,
            'email' => 'jane@example.com',
            'repository' => 'acme/widgets',
        ]);

        self::assertSame('saga-7', $email->sagaId);
        self::assertSame(99, $email->subscriptionId);
        self::assertSame('jane@example.com', $email->recipientEmail->value());
        self::assertSame('acme/widgets', $email->repository->value());
    }

    public function testFromArrayThrowsValidationOnMissingField(): void
    {
        $this->expectException(WelcomeRequestValidationException::class);

        $this->factory->fromArray([
            'sagaId' => 'saga-7',
            'subscriptionId' => 99,
            'email' => 'jane@example.com',
            // repository missing
        ]);
    }

    public function testFromArrayThrowsValidationOnNonIntegerSubscriptionId(): void
    {
        $this->expectException(WelcomeRequestValidationException::class);

        $this->factory->fromArray([
            'sagaId' => 'saga-7',
            'subscriptionId' => 'not-a-number',
            'email' => 'jane@example.com',
            'repository' => 'acme/widgets',
        ]);
    }

    public function testFromArrayThrowsValidationOnMalformedEmail(): void
    {
        $this->expectException(WelcomeRequestValidationException::class);

        $this->factory->fromArray([
            'sagaId' => 'saga-7',
            'subscriptionId' => 99,
            'email' => 'bogus',
            'repository' => 'acme/widgets',
        ]);
    }
}
