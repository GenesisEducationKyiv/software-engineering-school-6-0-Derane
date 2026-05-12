<?php

declare(strict_types=1);

namespace App\Service;

interface NotifierInterface
{
    public function sendReleaseNotification(
        string $email,
        string $repository,
        string $tagName,
        string $releaseName,
        string $releaseUrl,
        string $releaseBody
    ): bool;
}
