---
name: requirements-analysis
description: Convert an ambiguous request into testable, project-independent requirements.
---

# Requirements Analysis

Identify the goal, problem, users, expected behavior, constraints, success measures, and non-goals.

## Requirement Classification

Every statement in the analysis must be classified as one of the following:

- **Confirmed fact:** Directly stated by the user or verified in the project.
- **Proposed decision:** A recommended option offered for user approval.
- **Assumption:** A temporary belief needed to continue, clearly marked and easy to challenge.
- **Open question:** Missing information whose answer may change the result.
- **Recommendation:** Advice that is not part of the requirement unless accepted.

Never invent a latency target, data-volume limit, field, role, workflow, filter, integration, or acceptance criterion and present it as confirmed. If a value is useful, present it as a proposed target and explain why it matters.

## Scope Dependency Check

For every requested behavior, verify whether the project already contains the mechanism needed to support it.

- **Required supporting change:** Existing implementation must change to satisfy an explicitly confirmed requirement; keep the change narrowly scoped.
- **Optional enhancement:** A useful capability that is not necessary to satisfy the confirmed requirement; do not implement it without approval.
- **Scope dependency:** The requested behavior depends on a mechanism that does not exist. Surface the dependency as a proposed decision or open question and wait for confirmation if adding it would create a new user-visible capability, subsystem, dependency, or data contract.

Do not infer a UI control, filter, search system, counter, endpoint, role, metric, or other capability merely because it would make an edge case easier to handle. A phrase such as “filtered empty state” does not by itself approve adding filtering; confirm whether filtering is already in scope or whether the requirement should be narrowed.

Claims about what will not change are also not automatically facts. “This file will remain untouched”, “the schema will not change”, and “backward compatibility is guaranteed” must be classified as a confirmed constraint only when the user or project rules explicitly establish them; otherwise classify them as a proposed decision or assumption requiring confirmation.

When relying on an earlier report, label the evidence as prior evidence. A command is current evidence only when it was executed in the present verification step and its command and result are recorded.

## Output

- Goal and problem statement
- Users or actors
- User stories or use cases
- Functional requirements
- Non-functional requirements
- Acceptance criteria
- Explicit non-goals
- Open questions and assumptions
- Confirmed facts, proposed decisions, assumptions, open questions, and recommendations

Do not invent features, hide uncertainty, or choose technology before the requirement needs it.
