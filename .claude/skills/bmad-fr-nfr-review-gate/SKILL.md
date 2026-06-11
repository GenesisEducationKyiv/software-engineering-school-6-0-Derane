---
name: bmad-fr-nfr-review-gate
description: Run a BMAD spec-driven post-implementation review gate. Use after implementing a GitHub PR, feature, bugfix, or task with BMAD specs to verify every FR/NFR, pinned NonFunctionals.com NFR category, expanded quality dimension, Wikipedia system quality attribute, positive/negative/edge test case, automated test and CI coverage expectation, flaky-test risk, whole-codebase impact surface, manual test expectation, QA best practice, GitHub review comments and requested-changes state, and CI check before completion.
---

# BMAD FR/NFR Review Gate

Use this skill after implementation when a PR, feature, bugfix, or task has
BMAD specs under `specs/`. The gate checks whether the implementation
corresponds to every functional and non-functional requirement, verifies
expanded quality dimensions, every pinned system quality attribute, generated
positive/negative/edge test cases, automated test and CI coverage, flaky-test
risk, and related whole-codebase impact, then blocks completion until all
applicable rows score 5/5.

## Inputs

- BMAD spec bundle or file: `BMAD_REVIEW_SPEC_PATH=specs/my-bundle`
- Optional manual evidence: `BMAD_REVIEW_MANUAL_EVIDENCE=<path>`
- Optional PR number: `BMAD_REVIEW_PR=<number>`
- Optional base ref: `BMAD_REVIEW_BASE=<base-ref>`
- Graph impact context from Graphify/codebase-memory/Deptrac/manual notes:
  `BMAD_REVIEW_IMPACT_CONTEXT=<path>`
- PR publishing toggles: `BMAD_REVIEW_POST_PR_COMMENT=true|false` and
  `BMAD_REVIEW_POST_GITHUB_STATUS=true|false`. For PR runs, BMAD status
  publishing is required and defaults to `true`; disabling is only for
  local-only dry runs or test harnesses that must not write to GitHub.
- GitHub read/corroboration gate: `BMAD_REVIEW_REQUIRE_GITHUB_CI_CORROBORATION=true|false`.
  It defaults to `true` for PR publishing runs and `false` when both PR comment
  and GitHub status publishing are disabled for a local-only dry run.
- Optional status context: `BMAD_REVIEW_STATUS_CONTEXT='BMAD FR/NFR Review Gate'`
- Optional status self-filter override:
  `BMAD_REVIEW_STATUS_EXCLUDED_CONTEXT=<check-context>`; defaults to the final
  status context.

## Pinned NFR Catalog

The gate uses these NonFunctionals.com catalog categories:

- Performance
- Usability
- Maintainability
- Availability
- Interoperability
- Security
- Manageability
- Automatability
- Dependability

Do not add, remove, or rename categories during a review unless the skill is
being intentionally updated.

## Expanded Quality And Impact

The gate also requires an Expanded Quality Scorecard covering:

- Functional Suitability
- Performance Resource Sustainability
- Compatibility Coexistence
- Interaction Capability Accessibility
- Reliability Resilience
- Security Privacy Accountability
- Maintainability Testability
- Flexibility Portability
- Safety Harm Prevention
- Data Quality Integrity
- Operational Excellence Releaseability
- Observability Diagnosability
- Supply-Chain Integrity
- Compliance Governance
- Sustainability Resource Impact
- AI Automation Governance

It also requires a System Quality Attributes Scorecard covering every current
attribute from https://en.wikipedia.org/wiki/List_of_system_quality_attributes
as pinned by the wrapper. Each attribute must have a scored row with evidence,
source, status, and an improvement recommendation. If the wrapper list is
missing a current Wikipedia-listed attribute, or if an improvement, metric,
guardrail, test, CI check, or operational control is missing, the row fails and
the report must include a Required Fix.

The Whole-Codebase Impact Analysis must cover changed and related surfaces:
runtime paths, architecture/layer boundaries, domain model, persistence,
public API/schema, async events/queues, config/env, dependencies/lockfiles,
CI/workflows, tests/fixtures, docs, operations/observability,
security/privacy, and backward compatibility.

Graph/relationship evidence is required for whole-codebase impact scoring.
Graphify, codebase-memory MCP, Deptrac graph output, CodeQL, SCIP, or similar
tools can be supplied as impact context. If no context is supplied, the wrapper
generates a bounded local graph/relationship context from changed files and
direct symbol references; the reviewer still has to inspect related code rather
than relying only on changed files.
Every NFR catalog row, expanded quality row, and system quality attribute row
must cite graph/relationship evidence, or give a concrete source-backed reason
why graph evidence is irrelevant for that row.

## Scoring Contract

| Score | Meaning                                                          |
| ----- | ---------------------------------------------------------------- |
| 1/5   | Requirement not addressed or evidence absent                     |
| 2/5   | Partial implementation with major gaps                           |
| 3/5   | Implemented but missing tests, evidence, or important edge cases |
| 4/5   | Implemented and mostly verified with minor unresolved risk       |
| 5/5   | Fully implemented, verified, traceable, and review-ready         |

PASS requires all applicable FRs, NFRs, NFR catalog categories, expanded
quality dimensions, system quality attributes, generated test-case matrix rows,
automated test and CI coverage rows, flaky-test risk rows, whole-codebase impact
surfaces, manual-test requirements, QA checkpoints, GitHub completion checks,
and CI checks to score 5/5. It also requires review of vulnerabilities, bugs,
regressions, defects, operational problems, and data-loss/privacy/security
risks. A not-applicable row is allowed only with a concrete reason and source
evidence. Missing evidence fails closed.

## Mandatory QA Matrix

The reviewer must generate expected positive, negative, and edge/boundary/
race/timeout/error cases from every FR, NFR, acceptance criterion, story, and
quality requirement. It must map each repeatable case to automated tests and CI
checks, including applicable unit, integration, E2E, Behat, PHPUnit,
Schemathesis, K6/load, mutation, static-analysis, security-scan, and
contract/schema checks. Manual evidence is supporting evidence only for behavior
that cannot be fully automated. Missing repeatable automated coverage, missing
negative or edge tests, unmitigated flaky-test risk, or unreviewed
vulnerability/defect risk blocks PASS.

## Workflow

1. Read the BMAD spec bundle: PRD, architecture, epics/stories, research, and
   implementation-readiness files when present.
2. Extract every FR, NFR, acceptance criterion, story requirement, and readiness
   requirement with source path evidence.
3. Confirm expected positive, negative, and edge test classes from the spec so
   missing automated coverage can be treated as a blocker.
4. Run the review gate against the current implementation and tests.
5. If manual testing is required, record evidence in a markdown file at
   `var/manual-test-evidence/<task>.md` and include it in the review.
6. If the review reports `STATUS: FAIL`, apply fixes within the current PR
   scope, rerun `composer lint && ./vendor/bin/phpunit --no-coverage --testsuite Unit && composer psalm`,
   then rerun the gate.
7. Fetch and address GitHub comments when a PR exists.
8. Do not mark the PR/task complete until the gate reports `STATUS: PASS`,
   quality gates pass, GitHub comments are resolved, required checks pass, and
   no requested-changes review remains.
9. For PR work, leave the final BMAD result visible on the PR through a
   generated PR comment.

## Required PASS Markers

The review output must include:

```text
FR_NFR_SCORECARD: PASS
NFR_CATALOG_SCORECARD: PASS
EXPANDED_QUALITY_SCORECARD: PASS
SYSTEM_QUALITY_ATTRIBUTES_SCORECARD: PASS
WHOLE_CODEBASE_IMPACT: PASS
GRAPH_IMPACT_CONTEXT: PASS
TEST_CASE_MATRIX: PASS
AUTO_TEST_COVERAGE: PASS
FLAKY_TEST_RISK: PASS
MANUAL_TEST_EVIDENCE: PASS
QA_BEST_PRACTICES: PASS
GITHUB_COMPLETION_GATE: PASS
CI_GATE: PASS
```

The gate treats a `STATUS: PASS` without these markers as failure. `STATUS: PASS`
or `STATUS: FAIL` must be the exact first line of the review output. PASS also
requires `EXPANDED_QUALITY_MIN_SCORE: 5/5` and `IMPACT_ANALYSIS_MIN_SCORE: 5/5`
evidence markers, plus `SYSTEM_QUALITY_ATTRIBUTES_MIN_SCORE: 5/5`,
`TEST_CASE_COVERAGE_MIN_SCORE: 5/5`, `AUTO_TEST_COVERAGE_MIN_SCORE: 5/5`, and
`FLAKY_TEST_RISK_MIN_SCORE: 5/5`.

## Manual Evidence Format

Manual evidence must include:

- tester
- date
- scenario
- steps
- observed result
- linked artifacts or command output when available
- related FR/NFR IDs or NFR catalog categories

Do not fabricate manual evidence. If evidence is absent, leave the gate failing
and report the exact manual action required.

## Verification

Run quality gates for this project:

```bash
composer lint
./vendor/bin/phpunit --no-coverage --testsuite Unit
composer psalm
```
