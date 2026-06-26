<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;

final readonly class PHPMailerFactory implements MailerFactoryInterface
{
    #[\Override]
    public function create(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
