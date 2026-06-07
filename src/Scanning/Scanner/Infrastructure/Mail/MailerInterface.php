<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Infrastructure\Mail;

interface MailerInterface
{
    /**
     * @throws \Exception when delivery fails
     */
    public function send(string $recipient, RenderedEmail $email): void;
}
