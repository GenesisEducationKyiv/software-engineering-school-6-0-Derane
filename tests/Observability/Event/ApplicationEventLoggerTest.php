<?php

declare(strict_types=1);

namespace Tests\Observability\Event;

use App\Application\Event\ReleaseDetected;
use App\Application\Event\ReleaseNotificationSent;
use App\Application\Event\RepositoryScanFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\ScanInterruptedByRateLimit;
use App\Observability\Event\ApplicationEventLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ApplicationEventLoggerTest extends TestCase
{
    public function testReleaseDetectedLogsStableEventNameAndFields(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            LogLevel::INFO,
            'release.detected',
            [
                'event' => 'release.detected',
                'repository' => 'golang/go',
                'tag' => 'v1.22',
                'previous_tag' => 'v1.21',
            ]
        );

        $this->log($logger, new ReleaseDetected('golang/go', 'v1.22', 'v1.21'));
    }

    public function testScanCycleCompletedLogsDurationInMilliseconds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            LogLevel::INFO,
            'scan.cycle_completed',
            [
                'event' => 'scan.cycle_completed',
                'repository_count' => 3,
                'duration_ms' => 1500.0,
            ]
        );

        $this->log($logger, new ScanCycleCompleted(3, 1.5));
    }

    public function testRepositoryScanFailedLogsScalarErrorFieldsAtErrorLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            LogLevel::ERROR,
            'scan.repository_failed',
            [
                'event' => 'scan.repository_failed',
                'repository' => 'golang/go',
                'error_class' => \RuntimeException::class,
                'error' => 'boom',
            ]
        );

        $this->log($logger, new RepositoryScanFailed('golang/go', \RuntimeException::class, 'boom'));
    }

    public function testRateLimitedLogsAtWarningLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            LogLevel::WARNING,
            'scan.rate_limited',
            [
                'event' => 'scan.rate_limited',
                'repository' => 'golang/go',
                'retry_after' => '60',
            ]
        );

        $this->log($logger, new ScanInterruptedByRateLimit('golang/go', '60'));
    }

    public function testNotificationSentLogsRawEmailField(): void
    {
        // PII masking is centralized in EmailRedactingProcessor on the Monolog
        // logger, so this sink emits the raw email and the processor redacts it.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(
            LogLevel::INFO,
            'notification.sent',
            [
                'event' => 'notification.sent',
                'email' => 'user@example.com',
                'repository' => 'golang/go',
                'tag' => 'v1.22',
            ]
        );

        $this->log($logger, new ReleaseNotificationSent('user@example.com', 'golang/go', 'v1.22'));
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
