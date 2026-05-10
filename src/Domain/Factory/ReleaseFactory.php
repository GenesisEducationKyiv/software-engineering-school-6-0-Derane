<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\Release;

/** @psalm-api */
final class ReleaseFactory implements ReleaseFactoryInterface
{
    #[\Override]
    public function fromGitHubPayload(array $payload): Release
    {
        return new Release(
            isset($payload['tag_name']) ? (string) $payload['tag_name'] : null,
            isset($payload['name']) ? (string) $payload['name'] : '',
            isset($payload['html_url']) ? (string) $payload['html_url'] : '',
            isset($payload['published_at']) ? (string) $payload['published_at'] : '',
            isset($payload['body']) ? (string) $payload['body'] : '',
        );
    }
}
