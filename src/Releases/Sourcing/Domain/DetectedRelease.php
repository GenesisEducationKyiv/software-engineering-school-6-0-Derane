<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Domain;

use App\Shared\Domain\ValueObject\ReleaseTag;

/**
 * A Release whose tag is guaranteed present — the only kind the scanner ever
 * dispatches. Wrapping (rather than widening Release::$tagName) keeps the
 * GitHub-sourced snapshot honest: GitHub can return tagless releases, the
 * detector filters them out, and this type encodes that fact so downstream
 * consumers need no null-tag guards.
 *
 * @psalm-api
 */
final readonly class DetectedRelease
{
    public function __construct(
        public ReleaseTag $tag,
        public Release $release,
    ) {
    }
}
