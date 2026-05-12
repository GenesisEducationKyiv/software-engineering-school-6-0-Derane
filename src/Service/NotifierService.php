<?php

declare(strict_types=1);

namespace App\Service;

use App\Factory\MailerFactoryInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class NotifierService implements NotifierInterface
{
    /**
     * @param array{host: string, port: int, from: string, user: string, password: string,
     *              encryption: string} $smtpConfig
     */
    public function __construct(
        private array $smtpConfig,
        private LoggerInterface $logger,
        private MailerFactoryInterface $mailerFactory
    ) {
    }

    #[\Override]
    public function sendReleaseNotification(
        string $email,
        string $repository,
        string $tagName,
        string $releaseName,
        string $releaseUrl,
        string $releaseBody
    ): bool {
        $mail = $this->mailerFactory->create();

        try {
            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'];
            $mail->Port = $this->smtpConfig['port'];
            $mail->setFrom($this->smtpConfig['from'], 'GitHub Release Notifier');
            $mail->addAddress($email);

            if ($this->smtpConfig['user'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpConfig['user'];
                $mail->Password = $this->smtpConfig['password'];
            }

            if ($this->smtpConfig['encryption'] !== '') {
                $mail->SMTPSecure = $this->smtpConfig['encryption'];
            } else {
                $mail->SMTPAutoTLS = false;
            }

            $mail->isHTML(true);
            $mail->Subject = "New Release: {$repository} {$tagName}";
            $mail->Body = $this->buildHtmlBody($repository, $tagName, $releaseName, $releaseUrl, $releaseBody);
            $mail->AltBody = $this->buildTextBody($repository, $tagName, $releaseName, $releaseUrl, $releaseBody);

            $mail->send();
            $this->logger->info("Notification sent", [
                'email' => $email,
                'repository' => $repository,
                'tag' => $tagName,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to send notification", [
                'email' => $email,
                'repository' => $repository,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildHtmlBody(
        string $repository,
        string $tagName,
        string $releaseName,
        string $releaseUrl,
        string $releaseBody
    ): string {
        $escapedRepo = htmlspecialchars($repository);
        $escapedTag = htmlspecialchars($tagName);
        $escapedName = htmlspecialchars($releaseName);
        $escapedUrl = htmlspecialchars($releaseUrl);
        $escapedBody = nl2br(htmlspecialchars($releaseBody));

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>New Release for {$escapedRepo}</h2>
            <p><strong>Version:</strong> {$escapedTag}</p>
            <p><strong>Name:</strong> {$escapedName}</p>
            <div style="margin: 16px 0; padding: 12px; background: #f6f8fa; border-radius: 6px;">
                {$escapedBody}
            </div>
            <p><a href="{$escapedUrl}" style="color: #0366d6;">View Release on GitHub</a></p>
            <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 24px 0;">
            <p style="color: #586069; font-size: 12px;">
                You received this email because you subscribed to release notifications for {$escapedRepo}.
            </p>
        </div>
        HTML;
    }

    private function buildTextBody(
        string $repository,
        string $tagName,
        string $releaseName,
        string $releaseUrl,
        string $releaseBody
    ): string {
        return <<<TEXT
        New Release for {$repository}

        Version: {$tagName}
        Name: {$releaseName}

        {$releaseBody}

        View Release: {$releaseUrl}
        TEXT;
    }
}
