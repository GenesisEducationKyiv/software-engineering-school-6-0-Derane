<?php

declare(strict_types=1);

namespace App\Config\Factory;

use App\Config\SmtpConfig;

/** @psalm-api */
interface SmtpConfigFactoryInterface
{
    /** @param array{host: string, port: int, from: string, user: string, password: string, encryption: string} $config */
    public function fromArray(array $config): SmtpConfig;
}
