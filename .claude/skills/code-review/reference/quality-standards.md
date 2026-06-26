# Quality Standards Integration for Code Review

How to maintain quality standards when implementing PR review feedback.

> **For complete quality thresholds and commands, see the `quality-standards` skill**

## Core Principle

**Code reviews MUST maintain or improve quality metrics - NEVER decrease them.**

When implementing review feedback, quality standards are non-negotiable.

## PR Review Workflow

### 1. Apply Review Feedback

Implement changes as requested by reviewers.

### 2. Quick Verification After Each Change

```bash
make lint && make psalm && make deptrac && make test
```

(`make lint` = PHP_CodeSniffer PSR-12; autofix with `vendor/bin/phpcbf`.
`make psalm` = Psalm errorLevel 1 / 100% type coverage. `make deptrac` =
bounded-context boundaries. `make test` = PHPUnit Unit suite.)

### 3. Final Comprehensive Check

```bash
make ci  # MUST show "✅ CI checks successfully passed!"
```

### 4. If CI Fails

**Invoke the appropriate skill** based on failure type:

| Failure                | Invoke Skill              |
| ---------------------- | ------------------------- |
| Architecture violation | `deptrac-fixer`           |
| Test failures          | `testing-workflow`        |
| See complete mapping   | `quality-standards` skill |

## PR Review-Specific Scenarios

How to respond when review feedback conflicts with quality standards.

### Scenario 1: Review Suggests Bypassing the Architecture Boundary

**Review Comment**: "Just call the PDO connection directly from the Domain here"

**How to Respond**:

```
Thank you for the suggestion. However, that would make the Domain layer
depend on infrastructure (PDO), which deptrac blocks and the dependency rule
forbids (Domain ← Application ← Infrastructure).

Instead, I'll:
1. Define a port (interface) in the Domain/Application layer
2. Implement the PDO adapter in Infrastructure
3. Wire it through DI (bind the interface only)

This keeps the bounded-context boundaries intact while addressing the concern.
```

### Scenario 2: Review Suggests Skipping Tests

**Review Comment**: "This is trivial, tests not needed"

**How to Respond**:

```
We keep the Unit suite env-independent and green, and we do not ship
behavior without a test. All code must be tested, including trivial cases, to:

1. Document behavior via tests
2. Keep refactors safe (the Unit suite is the fast feedback gate)
3. Preserve the public contract (JSON shape, gRPC reply, Behat assertions)

I'll add tests including edge cases.
```

### Scenario 3: Review Suggests Suppressing a Gate

**Review Comment**: "Add a @psalm-suppress / grow the deptrac baseline to merge faster"

**How to Respond**:

```
Quality gates are protected and cannot be weakened. These are enforced by
`make ci` (Psalm errorLevel 1, PSR-12, deptrac). The deptrac baseline only
shrinks, never grows, and suppression annotations are forbidden in the diff.

Instead, I'll address the underlying issue by refactoring to meet the gate.
This keeps long-term maintainability and the architecture boundaries intact.

See the `quality-standards` skill for complete threshold details.
```

## Quick Checklist

**Before marking PR review as complete:**

- ✅ `make ci` shows "✅ CI checks successfully passed!"
- ✅ All review comments addressed
- ✅ No quality regressions introduced (no new deptrac baseline entries, no suppressions)
- ✅ All conversations resolved

**Related Skills:**

- `quality-standards` - Complete thresholds and commands
- `ci-workflow` - Comprehensive CI process
- `testing-workflow` - Fix test coverage issues
- `deptrac-fixer` - Fix architecture violations
