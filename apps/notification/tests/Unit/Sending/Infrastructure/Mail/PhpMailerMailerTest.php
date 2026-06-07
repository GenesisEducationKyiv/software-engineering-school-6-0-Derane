<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Mail;

use App\Sending\Domain\RenderedEmail;
use App\Sending\Infrastructure\Mail\MailerFactoryInterface;
use App\Sending\Infrastructure\Mail\PhpMailerMailer;
use App\Sending\Infrastructure\Mail\SmtpConfig;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PhpMailerMailerTest extends TestCase
{
    public function testSendsViaSmtpWithAuthAndEncryption(): void
    {
        $config = new SmtpConfig(
            host: 'smtp.example.com',
            port: 587,
            from: 'noreply@example.com',
            user: 'user',
            password: 'pass',
            encryption: 'tls',
        );

        /** @var PHPMailer&MockObject $phpMailer */
        $phpMailer = $this->createMock(PHPMailer::class);
        $factory = $this->mailerFactory($phpMailer);

        $phpMailer->expects(self::once())->method('isSMTP');
        $phpMailer->expects(self::once())->method('setFrom')->with('noreply@example.com', 'GitHub Release Notifier');
        $phpMailer->expects(self::once())->method('addAddress')->with('subscriber@example.com');
        $phpMailer->expects(self::once())->method('isHTML')->with(true);
        $phpMailer->expects(self::once())->method('send');

        $mailer = new PhpMailerMailer($config, $factory);
        $mailer->send('subscriber@example.com', new RenderedEmail('Subject', '<p>html</p>', 'text'));

        self::assertSame('smtp.example.com', $phpMailer->Host);
        self::assertSame(587, $phpMailer->Port);
        self::assertTrue($phpMailer->SMTPAuth);
        self::assertSame('user', $phpMailer->Username);
        self::assertSame('pass', $phpMailer->Password);
        self::assertSame('tls', $phpMailer->SMTPSecure);
        self::assertSame('Subject', $phpMailer->Subject);
        self::assertSame('<p>html</p>', $phpMailer->Body);
        self::assertSame('text', $phpMailer->AltBody);
    }

    public function testSkipsAuthAndDisablesAutoTlsWhenConfigHasNoCredentialsOrEncryption(): void
    {
        $config = new SmtpConfig(
            host: 'smtp.example.com',
            port: 25,
            from: 'noreply@example.com',
            user: '',
            password: '',
            encryption: '',
        );

        /** @var PHPMailer&MockObject $phpMailer */
        $phpMailer = $this->createMock(PHPMailer::class);
        $factory = $this->mailerFactory($phpMailer);

        $mailer = new PhpMailerMailer($config, $factory);
        $mailer->send('subscriber@example.com', new RenderedEmail('Subject', '<p>html</p>', 'text'));

        self::assertNotTrue($phpMailer->SMTPAuth);
        self::assertFalse($phpMailer->SMTPAutoTLS);
        self::assertSame('', $phpMailer->SMTPSecure);
    }

    /** @return MailerFactoryInterface&MockObject */
    private function mailerFactory(PHPMailer $phpMailer): MailerFactoryInterface&MockObject
    {
        $factory = $this->createMock(MailerFactoryInterface::class);
        $factory->method('create')->willReturn($phpMailer);

        return $factory;
    }
}
