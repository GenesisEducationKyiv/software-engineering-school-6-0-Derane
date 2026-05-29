<?php

declare(strict_types=1);

namespace App\Factory;

use PHPMailer\PHPMailer\PHPMailer;

/** @psalm-api */
final readonly class PHPMailerFactory implements MailerFactoryInterface
{
    #[\Override]
    public function create(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
