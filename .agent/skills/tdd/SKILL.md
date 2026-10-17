---
name: tdd
description: Apply behavior-focused test-driven development through small vertical slices and safe refactoring.
---

# Test-Driven Development

Use TDD when the project and task benefit from a test-first loop. Test behavior through public interfaces rather than implementation details. Respect the project’s test conventions and do not force TDD during analysis-only work or where no practical test seam exists.

## Use When

- Building a new behavior with a clear expected outcome.
- Fixing a reproducible bug that needs regression protection.
- Changing a public interface or an important domain rule.
- The user explicitly requests test-first development.

## Inputs

- Confirmed requirement or defect.
- Acceptance criteria and important edge cases.
- Existing test framework, commands, fixtures, and conventions.
- The public seam where behavior can be observed.

## Cycle

1. Agree on one behavior and the public seam to test.
2. State what the test catches and what it does not catch.
3. Write one focused failing test.
4. Confirm that it fails for the expected reason.
5. Implement the smallest change that makes it pass.
6. Run the focused test and relevant type or static checks.
7. Refactor only after the test passes.
8. Repeat in small vertical slices.

## Test Quality

- Describe user-visible or domain behavior.
- Prefer stable public interfaces over private implementation details.
- Keep each test focused on one meaningful behavior.
- Use realistic data and explicit boundaries.
- Avoid unnecessary mocks, snapshot overuse, timing dependence, and assertions on incidental structure.
- Keep tests deterministic and independent.

## Failure Handling

- If the new test passes immediately, confirm whether the behavior already exists or the test is ineffective.
- If it fails for infrastructure reasons, separate environment failure from product failure.
- If the seam is unclear, stop and identify the interface before adding tests.
- If the full suite is unavailable, report the focused evidence and untested scope.

## Output

Report the behavior tested, public seam, red-green-refactor evidence, commands run, changed tests and code, remaining untested areas, and residual risk.
