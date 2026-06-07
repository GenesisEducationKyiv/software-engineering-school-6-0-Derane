<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * This service's own `MailerFactoryInterface` adapter — recreated, not
 * imported, from the monolith's `App\Factory\PHPMailerFactory` (cross-deployable
 * boundary, same reasoning as `SmtpConfig`/`MailerFactoryInterface`).
 */
final readonly class PHPMailerFactory implements MailerFactoryInterface
{
    #[\Override]
    public function create(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
