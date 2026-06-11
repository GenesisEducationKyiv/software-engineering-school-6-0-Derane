---
name: bmad-fr-nfr-review-gate
description: >
  Codex entrypoint for post-implementation BMAD FR/NFR review gates. Use after
  a PR, feature, or bugfix has BMAD specs and must be checked against every
  functional requirement, non-functional requirement, expanded quality
  dimension, Wikipedia system quality attribute, generated positive/negative/
  edge test case, automated test and CI coverage expectation, flaky-test risk,
  whole-codebase impact surface, manual-test expectation, GitHub review
  comments and requested-changes state, and CI check before completion.
---

This is the Codex wrapper. The canonical workflow lives in
`.claude/skills/bmad-fr-nfr-review-gate/SKILL.md`.

Use this skill after implementation, not during planning. It requires a BMAD
spec bundle or spec file under `specs/`.

Quick usage:

```bash
BMAD_REVIEW_SPEC_PATH=specs/hw7-clean-architecture-microservices \
BMAD_REVIEW_PR=<number> \
/bmad-fr-nfr-review-gate
```

Optional inputs:

- `BMAD_REVIEW_MANUAL_EVIDENCE=path/to/evidence.md`
- `BMAD_REVIEW_PR=<number>`
- `BMAD_REVIEW_BASE=<base-ref>`
- `BMAD_REVIEW_IMPACT_CONTEXT=path/to/graph-or-impact-context.md`
- `BMAD_REVIEW_POST_PR_COMMENT=true|false`; required and default-on for PR
  runs, disable only for local-only dry runs or test harnesses.
- `BMAD_REVIEW_POST_GITHUB_STATUS=true|false`; required and default-on for PR
  runs, disable only for local-only dry runs or test harnesses.
- `BMAD_REVIEW_STATUS_CONTEXT='BMAD FR/NFR Review Gate'`

The gate uses the tracked AI review loop and BMAD-specific prompts. It fails
unless every applicable FR, NFR, pinned NonFunctionals.com category, expanded
quality dimension, Wikipedia system quality attribute, generated positive/
negative/edge test case, automated test and CI coverage row, flaky-test risk
row, whole-codebase impact surface, QA checkpoint, manual-test requirement,
GitHub completion gate, and CI gate has 5/5 evidence or an explicit
not-applicable reason with source evidence.

Read and follow `.claude/skills/bmad-fr-nfr-review-gate/SKILL.md` before
claiming completion.
