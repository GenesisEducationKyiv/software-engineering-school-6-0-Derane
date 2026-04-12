<?php

declare(strict_types=1);

namespace App\Service;

interface MetricsServiceInterface
{
    public function collect(): string;
}
