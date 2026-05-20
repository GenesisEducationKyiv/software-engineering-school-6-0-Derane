<?php

declare(strict_types=1);

namespace App\Repository;

interface ScanCandidateSource
{
    /** @return list<string> */
    public function getDueForScan(int $limit): array;
}
