---
name: quality-assurance
description: Select and report the right verification evidence for a change.
---

# Quality Assurance

Use the most relevant combination of static analysis, formatting, linting, type checking, unit tests, integration tests, API tests, end-to-end tests, accessibility checks, security checks, and manual verification.

## Verification Status

Report these statuses separately when relevant:

- **Implemented in source:** The code path exists in the inspected files.
- **Static check passed:** A named static check, lint, type check, or syntax check passed.
- **Runtime verified:** The behavior was exercised in the relevant runtime, browser, API, integration, or deployment environment.
- **Prior evidence:** The result comes from an earlier step or report and was not rerun now.
- **Unverified:** The relevant check was not performed or the environment was unavailable.

Never promote “implemented in source” to “operational”, “working”, “fully functional”, or “compatible” without runtime evidence.

When testing scope-control behavior, state whether the project was clean/original or already modified. A current-state inspection cannot prove what the Agent would have decided on the original baseline.

## Trace Accuracy

- Include the exact command as executed, without ellipses or placeholders.
- Label rerun checks as current evidence and unrepeated results as prior evidence.
- Cite verified Agent file paths and headings instead of guessed rule numbers.
- Recommend runtime or deployment commands only after discovering the project's server, routing, document root, and environment requirements.

## Output

Report passed checks, failed checks, untested areas, residual risks, and next actions. Never claim full correctness from partial tests or from a successful build alone.
