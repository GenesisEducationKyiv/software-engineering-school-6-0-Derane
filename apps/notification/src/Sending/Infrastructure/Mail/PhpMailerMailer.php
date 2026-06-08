<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use App\Sending\Domain\Mailer;
use App\Sending\Domain\RenderedEmail;

final readonly class PhpMailerMailer implements Mailer
{
    public function __construct(
        private SmtpConfig $config,
        private MailerFactoryInterface $mailerFactory,
    ) {
    }

    #[\Override]
    public function send(string $toEmail, RenderedEmail $rendered): void
    {
        $mail = $this->mailerFactory->create();

        $mail->isSMTP();
        $mail->Host = $this->config->host;
        $mail->Port = $this->config->port;
        $mail->setFrom($this->config->from, 'GitHub Release Notifier');
        $mail->addAddress($toEmail);

        if ($this->config->hasAuth()) {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config->user;
            $mail->Password = $this->config->password;
        }

        if ($this->config->hasEncryption()) {
            $mail->SMTPSecure = $this->config->encryption;
        } else {
            $mail->SMTPAutoTLS = false;
        }

        $mail->isHTML(true);
        $mail->Subject = $rendered->subject;
        $mail->Body = $rendered->htmlBody;
        $mail->AltBody = $rendered->textBody;

        $mail->send();
    }
}
