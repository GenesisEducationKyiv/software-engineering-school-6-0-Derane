<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;

interface MailerFactoryInterface
{
    public function create(): PHPMailer;
}
