---
trigger: always_on
type: verification_rule
---

# Testing and Verification

## Choosing Tests

- Pure logic changes: unit tests.
- Services or integrations: integration tests.
- APIs or controllers: feature/API tests.
- Critical user journeys: E2E tests.
- Visually sensitive interfaces: visual or manual QA.

## What to Test

- The happy path.
- Expected errors.
- Empty and boundary values.
- Authorization.
- Data transformations and mappings.
- External service failures.
- State transitions.

## Do Not Invent a Test Tool

Use the tools already present in the project. If none exist, propose an appropriate tool instead of installing one outside the agreed scope.

## Definition of Done

- Requirements are accepted.
- The main path works.
- Appropriate tests pass.
- No obvious build or type errors remain.
- Permissions and important edge cases are covered according to risk.
- Documentation is updated when behavior changed.

