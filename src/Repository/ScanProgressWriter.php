<?php

declare(strict_types=1);

namespace App\Repository;

interface ScanProgressWriter
{
    public function markChecked(string $fullName): void;

    public function markReleaseSeen(string $fullName, string $tag): void;
}
