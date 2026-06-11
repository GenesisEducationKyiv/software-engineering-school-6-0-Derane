---
name: testing-workflow
description: Run and manage functional tests (unit, integration, acceptance). Use when running tests, debugging test failures, or ensuring test coverage for changed behavior. Covers PHPUnit (Unit + Integration) and Behat acceptance.
---

# Testing Workflow Skill

## Context (Input)

- Code changes require test validation
- Test failures need debugging
- Changed behavior must ship with tests

## Task (Function)

Execute the appropriate test suite and ensure a 100% pass rate. Behavior ships
with tests; the env-independent Unit suite is the fast feedback gate.

## Test Commands Quick Reference

| Test Type            | Command                            | Needs Docker stack | Location                                  |
| -------------------- | ---------------------------------- | ------------------ | ----------------------------------------- |
| Unit (monolith)      | `make test`                        | No                 | `tests/Unit/`                             |
| Unit (notif service) | `make notification-unit`           | No                 | `apps/notification/tests/Unit/`           |
| Integration          | `make integration` *(or `make tests`)* | Yes (Postgres/Redis) | `tests/Integration/`                 |
| Integration (notif)  | `make notification-integration`    | Yes (Postgres/RabbitMQ/MailHog) | `apps/notification/tests/Integration/` |
| Acceptance (Behat)   | `make acceptance`                  | Yes (full stack)   | `features/`                               |
| Everything           | `make tests`                       | Yes                | All suites                                |

> The Unit suite is env-independent — run `make test` for quick validation. The
> rest need the Docker stack. `composer test` / `./vendor/bin/phpunit --testsuite Unit`
> is the raw equivalent of the Unit gate.

## Execution Workflow

### Step 1: Run Tests

```bash
make test            # quick env-independent Unit gate
make tests           # comprehensive check (needs Docker stack)
```

### Step 2: Check Results

- ✅ **All pass** → Complete
- ❌ **Failures detected** → Go to Step 3

### Step 3: Debug Failures

Identify the failure type and apply the fix:

| Failure Type      | Debug Source        | Common Fixes                              |
| ----------------- | ------------------- | ----------------------------------------- |
| Assertion failure | PHPUnit output      | Fix logic, update test expectations       |
| Missing coverage  | New/changed code    | Add tests for the new branch/behavior     |
| Behat scenario    | Feature output      | Fix application logic or step definitions |
| Type error        | Stack trace         | Fix type hints, mock returns              |
| Integration flake | Container logs      | Fix ordering/cleanup; keep tests deterministic |

### Step 4: Fix and Re-test

```bash
# Fix the code/tests, then:
make test            # re-run to verify
```

Repeat Steps 2-4 until all tests pass.

## Test Data With Faker

`fakerphp/faker` is available. Prefer dynamic test data over hardcoded values
so tests document intent and don't accidentally couple to literals.

```php
// Good - dynamic test data
$faker->email();
$faker->word();

// Avoid - hardcoded values where any valid value would do
'test@example.com'
```

Use the project's self-validating value objects (`EmailAddress`,
`RepositoryName`, `ReleaseTag`) when a test needs a valid domain value.

## Constraints (Parameters)

**NEVER**:

- Commit with failing tests
- Ship changed behavior without a test
- Weaken or skip a test to "save time"
- Break the public contract asserted by tests (JSON shape, gRPC reply, Behat)
- Run tests outside Docker for the suites that need the stack

**ALWAYS**:

- Keep the Unit suite env-independent and green
- Mock external dependencies (GitHub, SMTP, RabbitMQ) in unit tests
- Use the real DB/broker in integration tests
- Keep tests deterministic

## Format (Output)

**Unit Tests Success**:

```
OK (X tests, Y assertions)
```

## Verification Checklist

- [ ] All tests pass
- [ ] Changed behavior is covered by a test
- [ ] No hardcoded test values where dynamic data fits
- [ ] Stack-dependent suites run in Docker via `make`
- [ ] Public contract (JSON/gRPC/Behat) preserved

## Related Skills

- [ci-workflow](../ci-workflow/SKILL.md) - Run the full gate before commit
- [deptrac-fixer](../deptrac-fixer/SKILL.md) - Fix architecture violations surfaced by tests
- [quality-standards](../quality-standards/SKILL.md) - Overview of all protected gates
