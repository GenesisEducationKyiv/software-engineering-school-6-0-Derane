<?php

declare(strict_types=1);

namespace Tests\Scanning\Scanner\Infrastructure\Mail;

use App\Config\Factory\SmtpConfigFactory;
use App\Factory\PHPMailerFactory;
use App\Releases\Sourcing\Domain\Release;
use App\Scanning\Scanner\Infrastructure\Mail\ReleaseEmailRenderer;
use App\Scanning\Scanner\Infrastructure\Mail\SmtpMailer;
use App\Scanning\Scanner\Infrastructure\Mail\NotifierService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NotifierServiceTest extends TestCase
{
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

        return new NotifierService(
            new SmtpMailer($config, new PHPMailerFactory()),
            new ReleaseEmailRenderer(),
            new NullLogger()
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
    }
}
