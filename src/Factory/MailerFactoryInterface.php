<?php

declare(strict_types=1);

namespace App\Factory;

use PHPMailer\PHPMailer\PHPMailer;

interface MailerFactoryInterface
{
    public function create(): PHPMailer;
}
