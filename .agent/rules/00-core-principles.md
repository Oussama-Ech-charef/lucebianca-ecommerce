---
trigger: always_on
type: core_rule
---

# Core Principles

## Priorities

1. User and data safety.
2. The user's current instructions.
3. Existing project rules.
4. Correctness.
5. Maintainability.
6. Speed.

## Design Principles

- One responsibility per unit.
- Depend on abstractions when alternatives exist.
- Minimize coupling.
- Avoid duplicated business logic.
- Separate presentation, logic, and data concerns.
- Use clear names.
- Keep units small and testable.
- Make technical decisions explainable.

## Minimal Change

Make the smallest change that achieves the goal, unless a limited change would preserve an obvious defect or break the design.

## Scope Fidelity

- Classify proposed changes as **required behavior**, **supporting change**, or **optional enhancement**.
- A supporting change is allowed only when it is necessary to implement an explicitly confirmed requirement and does not introduce an unrelated user-visible capability.
- If a requirement refers to a state or behavior whose mechanism does not exist, do not invent the mechanism silently. Explain the dependency and ask whether to add it, keep the requested behavior narrower, or defer it.
- Examples of scope expansion include adding search/filter controls, new endpoints, new roles, new metrics, new persistence fields, new integrations, or new dependencies when they were not explicitly approved.
- Before implementation, compare the planned diff with the confirmed scope and report any new capability that would be added.

## Evidence First

Do not assume that a file, script, or framework exists. Verify its presence and actual usage.

## Verification Language

- “Implemented in source” means the relevant code path is present on disk; it does not prove that the feature works in a browser, runtime, deployment, or production environment.
- “Static check passed” applies only to the named check and scope.
- “Runtime verified” requires an actual execution, integration, browser, API, or deployment check appropriate to the claim.
- Use “unverified” when the relevant runtime check was not performed.
- Do not use “operational”, “working”, “fully functional”, or “compatible” as a substitute for missing runtime evidence.

## Baseline Integrity

- Always state whether conclusions describe the current state, a prior state, or an original/pre-change baseline.
- Prior reports, pasted output, and earlier diffs are prior evidence unless rechecked in the current step.
- A test on an already-modified project validates the current state only; it does not validate what the Agent would have done on a clean baseline.

## Requirement Fidelity

- Do not invent requirements, performance targets, data-volume limits, user roles, fields, workflows, or acceptance criteria.
- Label every item as a confirmed fact, proposed decision, assumption, open question, or recommendation.
- Treat inferred values such as latency targets or record limits as proposals until the user or project evidence confirms them.
- Ask for confirmation when an assumption would materially change scope, architecture, cost, security, or acceptance.

## Scope and Measurement Accuracy

- Resolve the workspace root, Agent configuration path, and project path before reading or reporting.
- Never confuse a configuration directory with the project being analyzed.
- Compute inventories from filesystem or repository evidence, not from manual counting.
- Reconcile totals before presenting them.

