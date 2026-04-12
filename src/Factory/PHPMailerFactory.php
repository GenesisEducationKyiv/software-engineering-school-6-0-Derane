<?php

declare(strict_types=1);

namespace App\Factory;

use PHPMailer\PHPMailer\PHPMailer;

class PHPMailerFactory implements MailerFactoryInterface
{
    public function create(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
