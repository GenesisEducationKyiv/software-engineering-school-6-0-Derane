---
name: quality-standards
description: Overview of protected quality gates and quick reference for all quality tools. Use when you need to understand quality metrics, run comprehensive quality checks, or learn which specialized skill to use. For specific issues, use dedicated skills (deptrac-fixer for deptrac, testing-workflow for tests, code-organization for structure/naming).
---

# Quality Standards Skill

## Context (Input)

- Need to understand protected quality gates
- Running comprehensive quality checks before commit
- Determining which specialized skill to use for specific issues
- Quick reference for quality tool commands

## Task (Function)

Understand the quality gates and route to the appropriate specialized skill for
fixes.

**Success Criteria**: Know which skill to use for your specific quality issue.

## Protected Quality Gates

**CRITICAL**: These gates are protected and must NEVER be weakened. They are
enforced by `make ci`.

| Tool             | Gate                          | Required        | Fix With                                           |
| ---------------- | ----------------------------- | --------------- | -------------------------------------------------- |
| PHP_CodeSniffer  | PSR-12 style                  | 0 violations    | `make lint` (autofix `vendor/bin/phpcbf`)          |
| Psalm            | Type errors                   | 0 errors        | Fix reported issues (no suppressions)              |
| Psalm            | Type coverage (`errorLevel 1`)| 100%            | Add/refine type annotations                        |
| deptrac          | Bounded-context boundaries    | 0 violations    | [deptrac-fixer](../deptrac-fixer/SKILL.md)         |
| deptrac          | `deptrac.baseline.yaml`       | only shrinks    | [deptrac-fixer](../deptrac-fixer/SKILL.md)         |
| PHPUnit          | Unit + Integration suites     | all green       | [testing-workflow](../testing-workflow/SKILL.md)   |
| Behat            | Acceptance scenarios          | all green       | [testing-workflow](../testing-workflow/SKILL.md)   |
| scanner-smoke    | One real scan delivers email  | pass            | [testing-workflow](../testing-workflow/SKILL.md)   |
| resilience-proof | Liveness + durable buffering  | pass            | -                                                  |
| composer audit   | Dependency advisories         | 0 vulnerabilities | Update/patch the flagged dependency              |

> We do **not** run PHPInsights, PHPMD, or Infection/mutation testing here, so
> there are no PHPInsights score / cyclomatic-complexity-percentage / MSI gates.
> Keep methods small and cohesive as a best practice, but the enforced gates are
> the ones above.

## Quick Reference Commands

### Comprehensive Check

```bash
make ci   # runs every gate; must end with "✅ CI checks successfully passed!"
```

### Individual Quality Checks

| Check               | Command                     | Purpose                          |
| ------------------- | --------------------------- | -------------------------------- |
| Code style          | `make lint`                 | PHP_CodeSniffer PSR-12           |
| Static analysis     | `make psalm`                | Type checking, errorLevel 1      |
| Architecture        | `make deptrac`              | Bounded-context boundaries       |
| Unit tests          | `make test`                 | Domain/Application logic (fast)  |
| All tests           | `make tests`               | Unit + Integration + acceptance  |
| Composer validation | `composer validate`         | Validate composer.json           |

## Routing to Specialized Skills

When quality checks fail, use the appropriate specialized skill:

### Architecture Issues

- **Deptrac violations** → [deptrac-fixer](../deptrac-fixer/SKILL.md)
  - Domain depends on Infrastructure / cross-context Infrastructure edges
  - Layer boundary violations, "must not depend on" errors

- **DDD architecture patterns** → [implementing-ddd-architecture](../implementing-ddd-architecture/SKILL.md)
  - Creating new entities/value objects/aggregates
  - Implementing CQRS command/query handlers
  - Understanding layer responsibilities

### Code Quality Issues

- **Structural/naming issues** → [code-organization](../code-organization/SKILL.md)
  - Class in wrong directory for its type (`Domain`/`Application`/`Infrastructure`)
  - Vague variable or class names
  - Hardcoded config that should be in env/`config`
  - Namespace doesn't match directory structure

- **Code style issues** → Run `make lint` (autofix `vendor/bin/phpcbf`)
  - PSR-12 violations, formatting issues

### Testing Issues

- **Test failures** → [testing-workflow](../testing-workflow/SKILL.md)
  - Unit/Integration/acceptance failures
  - Missing coverage for changed behavior

### Workflow Integration

- **Before committing** → [ci-workflow](../ci-workflow/SKILL.md)
- **PR review feedback** → [code-review](../code-review/SKILL.md)

## Quality Improvement Workflow

### Step 1: Run Comprehensive Checks

```bash
make ci
```

### Step 2: Identify the Failing Check

### Step 3: Use the Specialized Skill

| Failure Pattern        | Skill to Use             |
| ---------------------- | ------------------------ |
| "must not depend on"   | deptrac-fixer            |
| deptrac violations     | deptrac-fixer            |
| namespace/dir mismatch | code-organization        |
| tests failed           | testing-workflow         |
| Psalm found errors     | Fix type errors directly |

### Step 4: Re-run CI

```bash
make ci
```

Repeat until: "✅ CI checks successfully passed!"

## Constraints (Parameters)

### NEVER

- Weaken a gate in config (`psalm.xml`, `phpcs.xml.dist`, `deptrac.yaml`)
- Grow `deptrac.baseline.yaml` to "pass" architecture (fix code, not config)
- Skip failing checks to "save time"
- Commit code without all CI checks passing
- Add suppression/ignore annotations to hide issues (`@psalm-suppress`,
  `@phpstan-ignore*`, `phpcs:ignore`, `phpcs:disable`, `@codeCoverageIgnore*`)

### ALWAYS

- Fix code to meet standards (not config to meet code)
- Run `make ci` before creating commits
- Use specialized skills for specific quality issues
- Cover changed behavior with tests
- Respect the inward dependency rule and bounded-context boundaries

## Format (Output)

### Expected CI Output

```
✅ CI checks successfully passed!
```

### Expected Deptrac Output

```
[OK] would have been written to baseline file. 0 violations.
```

## Verification Checklist

- [ ] Identified which quality check is failing
- [ ] Selected the appropriate specialized skill
- [ ] Understand which gate applies to the failure
- [ ] Know the command to re-run the check after fixes

## Related Skills

- [ci-workflow](../ci-workflow/SKILL.md) - Run comprehensive CI validation
- [code-organization](../code-organization/SKILL.md) - Fix structural/naming issues
- [deptrac-fixer](../deptrac-fixer/SKILL.md) - Fix architectural violations
- [implementing-ddd-architecture](../implementing-ddd-architecture/SKILL.md) - Understand DDD patterns
- [testing-workflow](../testing-workflow/SKILL.md) - Fix test failures, improve coverage
