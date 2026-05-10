<?php

declare(strict_types=1);

namespace App\Notifier;

use App\Config\SmtpConfig;
use App\Factory\MailerFactoryInterface;

/** @psalm-api */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private SmtpConfig $config,
        private MailerFactoryInterface $mailerFactory
    ) {
    }

    #[\Override]
    public function send(string $recipient, RenderedEmail $email): void
    {
        $mail = $this->mailerFactory->create();

        $mail->isSMTP();
        $mail->Host = $this->config->host;
        $mail->Port = $this->config->port;
        $mail->setFrom($this->config->from, 'GitHub Release Notifier');
        $mail->addAddress($recipient);

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
        $mail->Subject = $email->subject;
        $mail->Body = $email->htmlBody;
        $mail->AltBody = $email->textBody;

        $mail->send();
    }
}
