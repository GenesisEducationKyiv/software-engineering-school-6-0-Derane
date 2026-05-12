<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Factory\PHPMailerFactory;
use App\Service\NotifierService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NotifierServiceTest extends TestCase
{
    private function createService(array $overrides = []): NotifierService
    {
        $config = array_merge([
            'host' => 'localhost',
            'port' => 1025,
            'user' => '',
            'password' => '',
            'from' => 'test@notifier.local',
            'encryption' => '',
        ], $overrides);

        return new NotifierService($config, new NullLogger(), new PHPMailerFactory());
    }

    public function testSendReleaseNotificationReturnsFalseOnSmtpFailure(): void
    {
        // With invalid SMTP host, sending will fail gracefully
        $service = $this->createService(['host' => 'invalid.host.that.does.not.exist', 'port' => 9999]);

        $result = $service->sendReleaseNotification(
            'user@example.com',
            'golang/go',
            'v1.22.0',
            'Go 1.22',
            'https://github.com/golang/go/releases/tag/v1.22.0',
            'Release notes'
        );

        $this->assertFalse($result);
    }

    public function testSendReleaseNotificationAcceptsAllParameters(): void
    {
        $service = $this->createService(['host' => 'invalid.host']);

        // Verify it doesn't throw, just returns false on connection failure
        $result = $service->sendReleaseNotification(
            'test@example.com',
            'owner/repo',
            'v2.0.0',
            'Major Release',
            'https://github.com/owner/repo/releases/tag/v2.0.0',
            "Line 1\nLine 2\n<script>alert('xss')</script>"
        );

        $this->assertFalse($result);
    }
}
