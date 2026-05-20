<?php

declare(strict_types=1);

namespace App\Config\Factory;

use App\Config\SmtpConfig;

/** @psalm-api */
final readonly class SmtpConfigFactory implements SmtpConfigFactoryInterface
{
    #[\Override]
    public function fromArray(array $config): SmtpConfig
    {
        return new SmtpConfig(
            $config['host'],
            $config['port'],
            $config['from'],
            $config['user'],
            $config['password'],
            $config['encryption']
        );
    }
}
