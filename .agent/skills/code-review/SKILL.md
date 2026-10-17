---
name: code-review
description: Review a change against both the project’s standards and the originating specification.
---

# Code Review

Review a defined diff against a clear fixed point such as a commit, branch, or tag. If no meaningful comparison point exists, state the limitation or ask for one when it materially affects the review. Review evidence, not assumptions, and never treat a clean diff as proof that the feature works.

## Use When

- The user requests a code review or change audit.
- A feature, bug fix, migration, or release needs an independent quality pass.
- A pull request, branch, commit range, or local diff is available.

## Inputs

- Fixed comparison point and current change set.
- Original request, ticket, specification, and acceptance criteria.
- Project rules, architecture, test commands, and security expectations.

## Review Setup

1. Resolve and confirm the fixed point.
2. Capture the changed-file list and diff once.
3. Confirm that the diff is non-empty and within the intended scope.
4. Read relevant project rules and the surrounding code before judging the change.
5. Run focused checks when safe and available.

## Two Review Axes

### Standards

Does the change follow the project’s documented architecture, conventions, security rules, tests, and maintainability expectations?

### Specification

Does the change faithfully implement the user request, ticket, acceptance criteria, and intended behavior?

## Output

Report findings with file, location, evidence, impact, severity, and recommendation. Separate confirmed defects from suggestions and unknowns.

Check:

- Correctness and regressions.
- Scope and specification coverage.
- Architecture and maintainability.
- Security, privacy, and authorization.
- Performance and resource use.
- Accessibility and responsive behavior.
- Tests, error paths, and observability.
- Documentation and migration safety.

End with passed checks, unresolved findings, untested areas, final risk, and recommended next step.
