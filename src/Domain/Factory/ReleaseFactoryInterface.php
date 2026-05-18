<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\Release;

interface ReleaseFactoryInterface
{
    /** @param array<string, mixed> $payload */
    public function fromGitHubPayload(array $payload): Release;
}
