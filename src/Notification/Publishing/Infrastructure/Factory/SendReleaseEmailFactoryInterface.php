<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Infrastructure\Factory;

use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Notification\Publishing\Domain\SendReleaseEmail;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * *FactoryInterface per CLAUDE.md's "anemic DTOs are constructed through a
 * *FactoryInterface — no from* static methods" rule. Builds a SendReleaseEmail
 * per-recipient from already-resolved Domain inputs (the construction-time ACL
 * C2's listener will call once per subscriber).
 *
 * Deliberately takes a ReleaseSnapshot (this context's own, self-sufficient
 * release VO — see ReleaseSnapshot's docblock) rather than
 * Releases\Sourcing\Domain\Release: the caller (C2's listener) maps the
 * GitHub-sourced Release into a ReleaseSnapshot first, keeping
 * Notification\Publishing decoupled from the Releases bounded context (no
 * cross-context Domain edge, no new deptrac grant).
 *
 * @psalm-api
 */
interface SendReleaseEmailFactoryInterface
{
    public function fromRecipient(
        int $subscriptionId,
        EmailAddress $email,
        RepositoryName $repository,
        ReleaseSnapshot $release
    ): SendReleaseEmail;
}
