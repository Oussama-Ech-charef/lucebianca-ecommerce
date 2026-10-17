---
trigger: always_on
type: quality_rule
---

# Architecture and Quality

## Layer Separation

When appropriate, separate:

- UI or presentation.
- Routing or transport.
- Validation.
- Application services.
- Domain logic.
- Data access.
- Infrastructure.

Do not impose these layers on a small project when they add complexity without value.

## Practical SOLID

- Do not combine multiple reasons to change in one unit.
- Use composition and interfaces when they solve a real problem.
- Do not tightly bind business logic to an external provider when alternatives exist.
- Avoid oversized interfaces.
- Make dependencies explicit.

## Data

- Design relationships before implementation when the project uses a data store.
- Put constraints in the correct source of truth.
- Validate at boundaries and enforce important invariants in the data store when appropriate.
- Watch for N+1 queries and unbounded reads.

## UI

- Preserve the existing design system.
- Reuse components.
- Use semantic HTML.
- Consider responsive behavior and accessibility.
- Do not change visual design when the request concerns logic only.

## Documentation

- Record important decisions.
- State assumptions.
- Link every workflow to the skill or skills it uses. Use `resources/workflow-skill-map.md` as the default mapping.

