<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;
use App\Notifier\MailerInterface;
use App\Notifier\ReleaseEmailRenderer;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class NotifierService implements NotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private ReleaseEmailRenderer $renderer,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function notifyReleaseAvailable(string $email, string $repository, Release $release): bool
    {
        try {
            $this->mailer->send($email, $this->renderer->render($repository, $release));

            $this->logger->info('Notification sent', [
                'email' => $email,
                'repository' => $repository,
                'tag' => $release->tagName,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send notification', [
                'email' => $email,
                'repository' => $repository,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
