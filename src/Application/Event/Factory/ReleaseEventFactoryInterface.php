<?php

declare(strict_types=1);

namespace App\Application\Event\Factory;

use App\Application\Event\ReleaseDetected;

/**
 * Builds release-detection events. Injected into
 * {@see \App\Service\ReleaseDetector}.
 */
interface ReleaseEventFactoryInterface
{
    public function releaseDetected(string $repository, string $tag, ?string $previousTag): ReleaseDetected;
}
