<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Factory\SmtpConfigFactory;
use App\Application\Event\Factory\ApplicationEventFactory;
use App\Application\Event\ReleaseNotificationFailed;
use App\Domain\Release;
use App\Factory\PHPMailerFactory;
use App\Notifier\MailerInterface;
use App\Notifier\ReleaseEmailRenderer;
use App\Notifier\SmtpMailer;
use App\Observability\Event\EventDispatcher;
use App\Observability\Logging\EmailMasker;
use App\Observability\Logging\FallbackLogger;
use App\Service\NotifierService;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingEventPublisher;
use Tests\Support\ThrowingListenerProvider;

class NotifierServiceTest extends TestCase
{
    private RecordingEventPublisher $events;

    private function createService(array $overrides = []): NotifierService
    {
        $config = (new SmtpConfigFactory())->fromArray(array_merge([
            'host' => 'localhost',
            'port' => 1025,
            'user' => '',
            'password' => '',
            'from' => 'test@notifier.local',
            'encryption' => '',
        ], $overrides));

        $this->events = new RecordingEventPublisher();

        return new NotifierService(
            new SmtpMailer($config, new PHPMailerFactory()),
            new ReleaseEmailRenderer(),
            $this->events,
            new ApplicationEventFactory()
        );
    }

    private function release(string $body = 'Release notes'): Release
    {
        return new Release(
            'v1.22.0',
            'Go 1.22',
            'https://github.com/golang/go/releases/tag/v1.22.0',
            '2024-02-06',
            $body
        );
    }

    public function testNotifyReturnsFalseOnSmtpFailure(): void
    {
        $service = $this->createService(['host' => 'invalid.host.that.does.not.exist', 'port' => 9999]);

        $this->assertFalse(
            $service->notifyReleaseAvailable('user@example.com', 'golang/go', $this->release())
        );

        $failures = $this->events->ofType(ReleaseNotificationFailed::class);
        $this->assertCount(1, $failures);
        $this->assertSame('user@example.com', $failures[0]->email);
        $this->assertSame('golang/go', $failures[0]->repository);
    }

    public function testNotifyAcceptsAllParameters(): void
    {
        $service = $this->createService(['host' => 'invalid.host']);

        $this->assertFalse(
            $service->notifyReleaseAvailable(
                'test@example.com',
                'owner/repo',
                $this->release("Line 1\nLine 2\n<script>alert('xss')</script>")
            )
        );

        $this->assertCount(1, $this->events->ofType(ReleaseNotificationFailed::class));
    }

    public function testSuccessfulSendStaysTrueEvenWhenAListenerThrows(): void
    {
        $service = new NotifierService(
            $this->createMock(MailerInterface::class),
            new ReleaseEmailRenderer(),
            new EventDispatcher(new ThrowingListenerProvider(), new FallbackLogger('test', 'test', new EmailMasker())),
            new ApplicationEventFactory()
        );

        $this->assertTrue(
            $service->notifyReleaseAvailable('user@example.com', 'golang/go', $this->release())
        );
    }
}
