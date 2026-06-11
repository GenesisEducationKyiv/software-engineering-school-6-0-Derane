---
name: ci-workflow
description: Run comprehensive CI checks before committing changes. Use when the user asks to run CI, run quality checks, validate code quality, or before finishing any task that involves code changes.
---

# CI Workflow Skill

## Context (Input)

- Code changes exist in the working directory
- Ready to validate code quality before commit/PR
- Need to ensure all quality standards are met

## Task (Function)

Execute `make ci` and ensure ALL quality checks pass with success message.

**Success Criteria**: Output ends with "✅ CI checks successfully passed!"

## What `make ci` Runs

`make ci` runs the full Dockerized pipeline sequentially; the first failing
stage aborts the run. Stages (see `Makefile`):

| Stage                  | Target                                 | Gate                                                     |
| ---------------------- | -------------------------------------- | ------------------------------------------------------- |
| Code style (monolith)  | `make lint`                            | PHP_CodeSniffer PSR-12                                   |
| Code style (notif svc) | `make notification-lint`               | PHP_CodeSniffer PSR-12 (apps/notification)              |
| Architecture (monolith)| `make deptrac`                         | deptrac bounded-context boundaries; baseline only shrinks |
| Architecture (notif)   | `make notification-deptrac`            | deptrac for the extracted service                       |
| Static analysis        | `make psalm` + `make notification-psalm` | Psalm errorLevel 1 (100% type coverage)               |
| Tests                  | `make tests`                           | PHPUnit Unit + Integration + notification + Behat acceptance |
| Scanner smoke          | `make scanner-smoke`                   | One real scan cycle delivers an email via MailHog       |
| Resilience proof       | `make resilience-proof`                | REST/gRPC liveness + durable buffering under outage     |

> The Integration, acceptance, scanner-smoke, and resilience stages need the
> Docker stack (Postgres/Redis/RabbitMQ/MailHog). The env-independent Unit gate
> alone is `make test`.

## Execution Steps

### Step 1: Run CI

```bash
make ci
```

### Step 2: Check Result

- ✅ **Success**: "✅ CI checks successfully passed!" → Task complete
- ❌ **Failure**: Task fails with error output → Go to Step 3

### Step 3: Fix Failures

Identify the failing stage from the output and apply the fix:

| Check           | Command                          | Fix                                 | Companion Skill                                    |
| --------------- | -------------------------------- | ----------------------------------- | -------------------------------------------------- |
| Code style      | `make lint` / `vendor/bin/phpcbf`| Apply PSR-12 auto-fixes             | -                                                  |
| Static analysis | `make psalm`                     | Fix type errors (no suppressions)   | -                                                  |
| Architecture    | `make deptrac`                   | Fix layer boundary violations       | [deptrac-fixer](../deptrac-fixer/SKILL.md)         |
| Organization    | `make deptrac` / review          | Fix naming, directory placement     | [code-organization](../code-organization/SKILL.md) |
| Tests           | `make test` / `make tests`       | Debug failing tests                 | [testing-workflow](../testing-workflow/SKILL.md)   |

**Refactoring during fixes**: If CI failures reveal structural issues (wrong
directory, vague names, hardcoded config), consult the
[code-organization](../code-organization/SKILL.md) skill before applying fixes.

### Step 4: Re-run

```bash
make ci
```

Repeat Steps 2-4 until the success message appears.

## Constraints (Parameters)

**NEVER weaken these gates**:

- PSR-12 style: clean (`make lint`)
- Static analysis: Psalm errorLevel 1, 100% type coverage
- Architecture: deptrac green; the `deptrac.baseline.yaml` baseline only shrinks, never grows
- Tests: Unit + Integration + notification suites green; behavior ships with tests
- Wire format preserved: JSON shape, gRPC reply, Behat assertions

**DO NOT**:

- Lower or bypass any gate
- Skip failing checks
- Grow the deptrac baseline to "pass" architecture
- Commit without the "✅ CI checks successfully passed!" message
- Run commands outside Docker (use `make` targets or `docker compose exec`)
- Add suppression/ignore annotations to silence Psalm/PHPCS/deptrac failures

## Format (Output)

**Required final output**:

```text
✅ CI checks successfully passed!
```

## Verification Checklist

- [ ] `make ci` executed
- [ ] All stages passed (style, deptrac, psalm, tests, scanner-smoke, resilience-proof)
- [ ] Output shows "✅ CI checks successfully passed!"
- [ ] Zero test failures
- [ ] No new deptrac baseline entries
- [ ] No gate weakened or suppressed

## Related Skills

- [code-organization](../code-organization/SKILL.md) - Consult when CI failures reveal structural/naming issues or hardcoded configs
- [deptrac-fixer](../deptrac-fixer/SKILL.md) - Fix architectural boundary violations
- [testing-workflow](../testing-workflow/SKILL.md) - Debug specific test failures
- [quality-standards](../quality-standards/SKILL.md) - Overview of all protected gates
