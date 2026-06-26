<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\ReleaseTag;

/**
 * Separate from Releases\Sourcing\Domain\Release to keep the integration
 * message decoupled from the Releases bounded context — reusing the GitHub-
 * sourced type would create a cross-context domain dependency and require a
 * new deptrac grant.
 *
 * @psalm-api
 */
final readonly class ReleaseSnapshot
{
    public function __construct(
        public ReleaseTag $tagName,
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body,
    ) {
    }
}
