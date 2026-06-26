<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure\Factory;

use App\Releases\Sourcing\Domain\Release;

interface ReleaseFactoryInterface
{
    /** @param array<string, mixed> $payload */
    public function fromGitHubPayload(array $payload): Release;
}
