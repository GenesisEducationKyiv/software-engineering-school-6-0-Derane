<?php

declare(strict_types=1);

namespace App\Notifier;

interface MailerInterface
{
    /**
     * @throws \Exception when delivery fails
     */
    public function send(string $recipient, RenderedEmail $email): void;
}
