# Decision Matrix

## Ask a Clarifying Question When

- The answer changes the technology stack, scope, data model, security boundary, or irreversible action.
- The requested behavior conflicts with an explicit project rule.
- Several options have materially different user or operational consequences.

## Load a Skill When

- The user explicitly requests it.
- The task clearly requires its specialized method.
- The project already defines an equivalent capability that must be followed.

## Refactor Only When

- The current structure blocks the requested change.
- A change is necessary to remove a concrete risk or duplication.
- The refactor directly improves the affected path and can be verified.

## Operation Type

| Type | Typical action |
|---|---|
| Read-only | Inspect, analyze, search, or report findings. |
| Reversible | Edit local files with a clear way to restore them. |
| External | Affect services, deployments, accounts, or other systems. |
| Destructive | Delete, overwrite, migrate, or permanently change data. |

Use the least risky action that satisfies the request.

Use `resources/operation-and-severity.md` when the operation type and the possible impact must both be reported.
