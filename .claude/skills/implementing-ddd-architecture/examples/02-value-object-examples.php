<?php

declare(strict_types=1);

/**
 * Example: Choosing the right value type (pragmatic, this-project edition).
 *
 * IMPORTANT: this project has THREE kinds of value, and picking the right one
 * is the whole lesson. Do NOT wrap everything in a VO, and do NOT skip the VO
 * where one belongs.
 *
 *   1) Self-validating Value Object  — validates itself in the constructor.
 *                                       Input validation lives HERE.
 *   2) Anemic readonly DTO snapshot  — pure data carrier, NO behavior/validation,
 *                                       built via a *FactoryInterface (no from*).
 *   3) Aggregate / Entity            — identity + lifecycle (see 01-entity-example.php).
 *
 * Unlike "pure" textbook DDD, this codebase does NOT use a framework validator,
 * annotations, or YAML validation configs. Constructing a self-validating VO IS
 * the validation. See ../REFERENCE.md - "Choosing the Right Value Type".
 */

// ============================================================================
// ❌ DON'T DO THIS - inline validation instead of the self-validating VO
// ============================================================================

namespace Example\AntiPatterns;

/**
 * ❌ BAD: an "email" carried as a raw string, validated ad-hoc at each call site.
 *
 * Why this is wrong here:
 *   - Validation gets duplicated and drifts (primitive obsession).
 *   - It bypasses App\Shared\Domain\ValueObject\EmailAddress, the single place
 *     where "what is a valid email" is defined.
 *   - It does not raise Shared\Domain\Exception\InvalidArgumentException, so the
 *     error never maps cleanly to 400 / INVALID_ARGUMENT via ExceptionStatusMap.
 */
final class SubscribeServiceBad
{
    public function subscribe(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { // ❌ inline validation
            throw new \RuntimeException("Invalid email: {$email}");
        }
        // ... use $email as a bare string everywhere downstream ...
    }
}

/**
 * ❌ BAD: a `from*` static constructor bolted onto an anemic data carrier.
 *
 * Why this is wrong here:
 *   - `from*` static methods are banned on anemic DTO snapshots; assembly belongs
 *     in a *FactoryInterface (see 03 + 04). `fromString()` is reserved for
 *     self-validating VOs only.
 */
final readonly class ReleaseSnapshotBad
{
    public function __construct(public ?string $tagName, public string $name) {}

    /** @param array<string, mixed> $payload */
    public static function fromGitHubPayload(array $payload): self // ❌ from* on a DTO
    {
        return new self($payload['tag_name'] ?? null, (string) ($payload['name'] ?? ''));
    }
}

// ============================================================================
// ✅ CASE 1 - Self-validating Value Object (input validation lives here)
// ============================================================================

/**
 * ✅ GOOD: RepositoryName — a value that MUST be valid by construction.
 *
 * Mirrors the real App\Shared\Domain\ValueObject\RepositoryName. Justified as a
 * VO because:
 *   - It carries an invariant ("owner/repo") enforced in the constructor.
 *   - It has identity-preserving behavior: owner(), repo(), equals(), __toString().
 *   - It is shared across Subscription, Releases, RepositoryTracking, Notification.
 *
 * A self-validating VO MAY expose a fromString() named constructor — that idiom
 * is for VOs, NOT for the from*-banned anemic DTOs.
 *
 * Location (real): src/Shared/Domain/ValueObject/RepositoryName.php
 */
namespace Example\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

final readonly class RepositoryName implements \Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(private string $value)
    {
        // Constructing the VO IS the validation. A bad value throws
        // Shared\Domain\Exception\InvalidArgumentException -> 400 / INVALID_ARGUMENT.
        if (!(bool) preg_match(self::PATTERN, $this->value)) {
            throw new InvalidArgumentException('Invalid repository format. Expected: owner/repo');
        }
    }

    /** Optional named constructor — fine on a self-validating VO. */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** ✅ Identity-preserving behavior — the reason this is a VO, not a string. */
    public function owner(): string
    {
        return substr($this->value, 0, (int) strpos($this->value, '/'));
    }

    public function repo(): string
    {
        return substr($this->value, (int) strpos($this->value, '/') + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * ✅ GOOD: ReleaseTag — a minimal self-validating VO.
 *
 * The tag is opaque: the ONLY rule is non-empty / non-whitespace. No format
 * regex, no normalization — the original value is stored verbatim. It is still
 * a VO (not a string) so "a present, valid tag" is unforgeable in the type system.
 *
 * Location (real): src/Shared/Domain/ValueObject/ReleaseTag.php
 */
namespace Example\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

final readonly class ReleaseTag implements \Stringable
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('Invalid release tag: must not be empty');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}

// ============================================================================
// ✅ CASE 2 - Anemic readonly DTO snapshot (pure data carrier, NO validation)
// ============================================================================

/**
 * ✅ GOOD: Release — a snapshot of a GitHub release.
 *
 * Justified as an anemic readonly DTO (NOT a VO, NOT an entity) because:
 *   - It has no identity and no lifecycle (it's just the data we fetched).
 *   - It carries NO validation and NO behavior — promoting it to a VO would add
 *     ceremony with no business rule to enforce.
 *   - It is assembled from raw GitHub JSON via ReleaseFactoryInterface — NOT via
 *     a from* static method on the class itself.
 *
 * Location (real): src/Releases/Sourcing/Domain/Release.php
 */
namespace Example\Releases\Sourcing\Domain;

final readonly class Release
{
    public function __construct(
        public ?string $tagName,     // nullable: a repo may have no tagged release
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body
    ) {
        // NO validation here — this is a pass-through snapshot. If a field needed
        // an invariant, it would be a self-validating VO instead.
    }
}

/**
 * ✅ The matching factory interface — the ONLY sanctioned construction path for
 * the anemic snapshot in production code. Lives in Infrastructure so Domain stays
 * pure; the snapshot itself stays in Domain.
 *
 * Location (real): src/Releases/Sourcing/Infrastructure/Factory/ReleaseFactoryInterface.php
 */
namespace Example\Releases\Sourcing\Infrastructure\Factory;

use Example\Releases\Sourcing\Domain\Release;

interface ReleaseFactoryInterface
{
    /** @param array<string, mixed> $payload */
    public function fromGitHubPayload(array $payload): Release;
}

// ============================================================================
// ✅ CASE 3 - Aggregate / Entity (identity + lifecycle) → see 01-entity-example.php
// ============================================================================

/*
 * When the value has identity AND a lifecycle (state transitions, recorded
 * events), it is neither a VO nor a DTO — it's an aggregate. See:
 *   - Example\RepositoryTracking\Repositories\Domain\RepositoryStatus (01-entity-example.php)
 *   - App\Subscription\Subscriptions\Domain\Subscription
 */

// ============================================================================
// DECISION GUIDE
// ============================================================================

/**
 * DECISION TREE:
 *
 *   Does it have identity + lifecycle (state transitions, events)?
 *   ├─ YES → Aggregate / Entity (extends AggregateRoot)
 *   └─ NO  → Must it be valid by construction (input validation / invariant)?
 *       ├─ YES → Self-validating Value Object
 *       │         (validate in the constructor; may expose fromString())
 *       │         e.g. EmailAddress, RepositoryName, ReleaseTag
 *       └─ NO  → Anemic readonly DTO snapshot
 *                 (no behavior, no validation; build via a *FactoryInterface)
 *                 e.g. Release, ReleaseSnapshot, SubscriberRef
 *
 * ✅ USE A SELF-VALIDATING VALUE OBJECT WHEN:
 *    - The value must be valid by construction (EmailAddress, RepositoryName, ReleaseTag)
 *    - It has identity-preserving behavior (equals(), owner()/repo(), __toString())
 *    - It is shared across contexts
 *
 * ✅ USE AN ANEMIC readonly DTO SNAPSHOT WHEN:
 *    - It only carries data across a boundary (a GitHub release snapshot)
 *    - There is no invariant to enforce and no behavior to add
 *    - Build it via a *FactoryInterface; NEVER add from* static methods to it
 *
 * ✅ USE AN AGGREGATE / ENTITY WHEN:
 *    - It has identity + a lifecycle, enforces invariants, and records events
 *
 * ❌ DON'T:
 *    - Inline filter_var / regex validation in a service or handler
 *      → construct the self-validating Shared VO instead
 *    - Reintroduce standalone App\Validation\* validator classes
 *      → they were absorbed into the VOs
 *    - Add a from* static constructor to an anemic DTO
 *      → use a *FactoryInterface (fromString() is VO-only)
 *    - Add behavior/validation to an anemic snapshot
 *      → promote it to a real VO or entity instead
 *
 * VALIDATION FLOW IN THIS CODEBASE:
 *
 *   1. Driver (Slim controller / gRPC handler / CLI) checks transport SHAPE only
 *      (missing fields, non-JSON body) -> ValidationException; then builds a Command/Query.
 *   2. Application handler constructs the Shared VOs from the command's primitives
 *      -> a bad value throws Shared\Domain\Exception\InvalidArgumentException.
 *   3. ExceptionStatusMap (one source of truth) maps both to 400 / INVALID_ARGUMENT.
 *   4. Domain methods enforce business invariants only — never input format.
 *
 * KEY PRINCIPLES:
 *   ✅ Validation lives in the self-validating Shared VOs.
 *   ✅ Anemic snapshots are pure data, built via factories.
 *   ✅ Promote to an aggregate only when there's identity + lifecycle.
 *   ✅ Keep it simple (YAGNI) — pick the lightest type that fits.
 *
 * REMEMBER: the goal is maintainable code that respects this project's conventions,
 * not "pure" DDD for its own sake.
 *
 * See: ../REFERENCE.md - "Choosing the Right Value Type".
 */
