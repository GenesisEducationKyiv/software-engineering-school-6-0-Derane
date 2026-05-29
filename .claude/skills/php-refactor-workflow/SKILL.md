---
name: php-refactor-workflow
description: Trigger for PHP refactor or code-review tasks in this project — auditing SOLID/GRASP violations, splitting fat services, restructuring repositories, or reviewing a pending change. Skip for one-line bug fixes, trivial scripts, framework configuration, or non-OOP PHP.
---

# php-refactor-workflow

Encodes the refactor workflow for this PHP project: audit first, confirm scope, execute in dependency order, validate, re-review.

Project conventions (where things live, naming, DI rules, quality gates) live in `CLAUDE.md`. This skill does not repeat them — it owns the **process**.

## When triggered

1. **Audit before refactoring.** Find SOLID/GRASP violations and list them grouped by principle with `file:line` refs. Do not start editing until the user confirms scope.
2. **Identify wire-format constraints.** What's the public contract (JSON shape, gRPC reply, Behat assertions)? Internal refactors must preserve these unless the user opts in.
3. **Update tests as part of the change.** Class-level contracts changed → tests change too. Never leave a broken suite.
4. **Re-run psalm + phpunit + lint** after every meaningful step. See `CLAUDE.md` for commands.

## Refactor session pattern

1. **Investigate** — read affected layers; produce a grouped audit with `file:line` refs.
2. **Confirm scope** — ask: full pass, partial, or minimum viable. Ask whether tests update in the same change.
3. **Execute in dependency order** — DTOs → factories → repositories → services → boundaries → tests → container.
4. **Validate** — psalm + phpunit + phpcs after each major step.
5. **Re-review** — be honest; if everything was addressed cleanly, say so. If not, name specific files and lines.

## Code review checklist

When reviewing PHP code or a refactor in this project:

1. **`array{...}` annotations crossing layers** → DTO candidate.
2. **Validation regex / `filter_var` inside services** → move to a Validator.
3. **Static `from*` methods on DTOs** → flip to factory interface + concrete impl.
4. **Fat interface serving multiple consumers** → split via per-consumer ISP. If the implementation class itself mixes read and write, split the class too.
5. **Methods > ~40 lines doing multiple jobs** → look for extraction candidates (Pure Fabrications).
6. **Duplicate serialization** (private `toPayload` re-implementing `toArray`) → call the canonical method.
7. **Two places mapping exception → transport status** → unify into `ExceptionStatusMap`.
8. **Concrete class as DI key** → bind interfaces only; alias when sharing instances.
9. **Naming collisions** (`SubscriptionRepository` vs `SubscriberRepository`) → rename to reveal role.
10. **Missing tests for newly extracted classes** → each Pure Fabrication deserves a focused test.
11. **Control-flow exception caught too deep** (e.g., rate limit handled inside the unit method instead of the orchestrator) → bubble it to the orchestrator.
12. **Edge cases in "safe" refactors** — `array_is_list([])` returns true; check empty-input paths.
13. **Wire format preserved?** Behat / contract tests still green?

## User-facing reply style

- **Audit responses:** group findings by SOLID/GRASP principle with `file:line` refs. Do not start editing without confirmation.
- **Refactor approvals:** ask scope before sprawling (full vs partial). Ask whether tests update in the same change.
- **Reviews:** be specific — file path, line, principle violated, suggested fix. If clean, say so; don't invent issues. Re-check honestly before declaring clean.
- **Done-claims:** state which gates ran (psalm/phpunit/lint) and the result.

## Things to avoid in this workflow

- Sprawling refactors without user-confirmed scope.
- Breaking the public contract (JSON, gRPC, Behat) during an internal refactor unless the user opted in.
- Declaring "done" without running all three quality gates.
- Skipping the audit step on "small" refactors — small refactors are exactly where assumed-safe edits hurt.
- Splitting an interface for ISP but leaving the fat implementation class untouched — that solves consumer overcasting but not class-level SRP. Re-check the class after the interface split.
