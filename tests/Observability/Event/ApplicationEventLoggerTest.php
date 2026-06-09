<?php

declare(strict_types=1);

namespace Tests\Observability\Event;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ReleaseDetected;
use App\Application\Event\ReleaseMarkerWithheld;
use App\Application\Event\ReleaseNotificationFailed;
use App\Application\Event\ReleaseNotificationSent;
use App\Application\Event\RepositoryScanFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\ScanCycleFailed;
use App\Application\Event\ScanCycleStarted;
use App\Application\Event\ScanInterruptedByRateLimit;
use App\Application\Event\SubscriptionCreated;
use App\Application\Event\SubscriptionDeleted;
use App\Observability\Event\ApplicationEventLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ApplicationEventLoggerTest extends TestCase
{
    /**
     * @param LogLevel::* $level
     * @param array<string, scalar|null> $context
     */
    #[DataProvider('eventMappingProvider')]
    public function testEventIsLoggedWithStableNameLevelAndFields(
        object $event,
        string $level,
        string $name,
        array $context,
    ): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            $level,
            $name,
            ['event' => $name] + $context
        );

        $this->log($logger, $event);
    }

    /**
     * @return iterable<string, array{object, LogLevel::*, non-empty-string, array<string, scalar|null>}>
     */
    public static function eventMappingProvider(): iterable
    {
        yield 'scan cycle started' => [
            new ScanCycleStarted(3),
            LogLevel::INFO,
            'scan.cycle_started',
            ['repository_count' => 3],
        ];

        yield 'scan cycle completed — duration logged in seconds' => [
            new ScanCycleCompleted(3, 1.5),
            LogLevel::INFO,
            'scan.cycle_completed',
            ['repository_count' => 3, 'duration_seconds' => 1.5],
        ];

        yield 'repository scan failed at error level with scalar error fields' => [
            new RepositoryScanFailed('golang/go', \RuntimeException::class, 'boom'),
            LogLevel::ERROR,
            'scan.repository_failed',
            ['repository' => 'golang/go', 'error_class' => \RuntimeException::class, 'error' => 'boom'],
        ];

        yield 'rate limited at warning level' => [
            new ScanInterruptedByRateLimit('golang/go', '60'),
            LogLevel::WARNING,
            'scan.rate_limited',
            ['repository' => 'golang/go', 'retry_after' => '60'],
        ];

        yield 'scan cycle failed at error level' => [
            new ScanCycleFailed(\RuntimeException::class, 'boom'),
            LogLevel::ERROR,
            'scan.cycle_failed',
            ['error_class' => \RuntimeException::class, 'error' => 'boom'],
        ];

        yield 'release detected' => [
            new ReleaseDetected('golang/go', 'v1.22', 'v1.21'),
            LogLevel::INFO,
            'release.detected',
            ['repository' => 'golang/go', 'tag' => 'v1.22', 'previous_tag' => 'v1.21'],
        ];

        yield 'notification batch fully delivered logs result=sent' => [
            new NotificationBatchCompleted('golang/go', 'v1.22', true),
            LogLevel::INFO,
            'notification.batch_completed',
            ['repository' => 'golang/go', 'tag' => 'v1.22', 'result' => 'sent'],
        ];

        yield 'notification batch with failures logs result=failed' => [
            new NotificationBatchCompleted('golang/go', 'v1.22', false),
            LogLevel::INFO,
            'notification.batch_completed',
            ['repository' => 'golang/go', 'tag' => 'v1.22', 'result' => 'failed'],
        ];

        yield 'release marker withheld at warning level' => [
            new ReleaseMarkerWithheld('golang/go', 'v1.22'),
            LogLevel::WARNING,
            'notification.marker_withheld',
            ['repository' => 'golang/go', 'tag' => 'v1.22'],
        ];

        // Raw email by design: PII masking is centralized in
        // EmailRedactingProcessor on the Monolog logger, not in this sink.
        yield 'notification sent logs raw email — masking is downstream' => [
            new ReleaseNotificationSent('user@example.com', 'golang/go', 'v1.22'),
            LogLevel::INFO,
            'notification.sent',
            ['email' => 'user@example.com', 'repository' => 'golang/go', 'tag' => 'v1.22'],
        ];

        yield 'notification failed at error level' => [
            new ReleaseNotificationFailed('user@example.com', 'golang/go', \RuntimeException::class, 'boom'),
            LogLevel::ERROR,
            'notification.failed',
            [
                'email' => 'user@example.com',
                'repository' => 'golang/go',
                'error_class' => \RuntimeException::class,
                'error' => 'boom',
            ],
        ];

        yield 'subscription created' => [
            new SubscriptionCreated('user@example.com', 'golang/go'),
            LogLevel::INFO,
            'subscription.created',
            ['email' => 'user@example.com', 'repository' => 'golang/go'],
        ];

        yield 'subscription deleted' => [
            new SubscriptionDeleted(42),
            LogLevel::INFO,
            'subscription.deleted',
            ['id' => 42],
        ];
    }

    public function testUnknownEventIsNotLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('log');

        $this->log($logger, new \stdClass());
    }

    private function log(LoggerInterface $logger, object $event): void
    {
        (new ApplicationEventLogger($logger))($event);
    }
}
