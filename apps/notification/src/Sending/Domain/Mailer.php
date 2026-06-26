<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface Mailer
{
    public function send(EmailAddress $toEmail, RenderedEmail $rendered): void;
}
