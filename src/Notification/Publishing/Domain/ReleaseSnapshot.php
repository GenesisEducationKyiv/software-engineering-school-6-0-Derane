<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Shared\Domain\ValueObject\ReleaseTag;

/**
 * Anemic, wire-format-shaped snapshot of a release as carried inside
 * SendReleaseEmail's nested "release" object (architecture §7).
 *
 * Deliberately a SEPARATE, smaller VO from Releases\Sourcing\Domain\Release:
 * that type is the GitHub-sourced internal snapshot (nullable tagName, plus a
 * "body" field this wire contract never exposes). Reusing it here would couple
 * an integration message's stability to an unrelated bounded context's Domain
 * type and require a cross-context deptrac grant. An integration message must
 * be self-sufficient and decoupled — so this VO is scoped to
 * Notification\Publishing\Domain and maps 1:1 onto §7's nested JSON shape.
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
