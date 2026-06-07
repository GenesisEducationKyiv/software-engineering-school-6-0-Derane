<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * This service's own `PHPMailer`-construction seam — recreated, not imported,
 * from the monolith's `App\Factory\MailerFactoryInterface` (cross-deployable
 * boundary, same reasoning as `SmtpConfig`). Exists so `PhpMailerMailerTest`
 * can drive `PhpMailerMailer::send()` against a mocked `PHPMailer` without
 * opening a real SMTP connection — identical seam-purpose to the monolith's
 * `MailerFactoryInterface`/`PHPMailerFactory` pairing.
 */
interface MailerFactoryInterface
{
    public function create(): PHPMailer;
}
