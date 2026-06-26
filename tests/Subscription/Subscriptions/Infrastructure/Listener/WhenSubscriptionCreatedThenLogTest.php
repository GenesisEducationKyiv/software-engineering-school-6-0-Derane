<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Infrastructure\Listener;

use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use App\Subscription\Subscriptions\Infrastructure\Listener\WhenSubscriptionCreatedThenLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WhenSubscriptionCreatedThenLogTest extends TestCase
{
    public function testLogsTheCreatedSubscriptionWithEmailAndRepositoryContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Subscription created', [
                'email' => 'test@example.com',
                'repository' => 'golang/go',
            ]);

        $listener = new WhenSubscriptionCreatedThenLog($logger);

        $listener(new SubscriptionCreated(
            'test@example.com',
            'golang/go',
            new \DateTimeImmutable('2026-06-07T12:00:00+00:00')
        ));
    }
}
