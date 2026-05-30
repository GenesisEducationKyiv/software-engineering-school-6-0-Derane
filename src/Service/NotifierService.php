<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Event\Factory\NotificationEventFactoryInterface;
use App\Domain\Release;
use App\Notifier\MailerInterface;
use App\Notifier\ReleaseEmailRenderer;
use App\Application\Event\EventPublisherInterface;

/** @psalm-api */
final readonly class NotifierService implements NotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private ReleaseEmailRenderer $renderer,
        private EventPublisherInterface $events,
        private NotificationEventFactoryInterface $eventFactory
    ) {
    }

    #[\Override]
    public function notifyReleaseAvailable(string $email, string $repository, Release $release): bool
    {
        try {
            $this->mailer->send($email, $this->renderer->render($repository, $release));
            $this->events->publish($this->eventFactory->notificationSent($email, $repository, $release->tagName));

            return true;
        } catch (\Exception $e) {
            $this->events->publish($this->eventFactory->notificationFailed($email, $repository, $e));
            return false;
        }
    }
}
