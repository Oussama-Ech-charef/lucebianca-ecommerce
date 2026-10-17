# Motion and Interaction Workflow

## Related Skills

`motion-design`, `product-design`, and `frontend`, with `accessibility-internationalization`, `web-design-guidelines`, `mobile`, or `agent-browser` when required.

## Before Implementation

1. Understand the user task, surface mode, content, and current interaction states.
2. Inspect the existing stack, animation conventions, design tokens, and lifecycle model.
3. Decide whether motion is necessary and what problem it solves.
4. Select the smallest suitable mechanism using `resources/motion-decision-matrix.md`.
5. Write a motion specification for each non-trivial interaction.
6. Define reduced-motion, keyboard, touch, interruption, and cleanup behavior.
7. Check the scope gate before adding dependencies or new interaction systems.

## During Implementation

- Implement incrementally and keep motion close to the component or surface that owns it.
- Prefer existing tokens, components, and project-native patterns.
- Avoid animation that changes meaning, traps focus, or delays essential actions.
- Stop and ask for confirmation if implementation reveals an unapproved library, gesture system, or visual capability.

## Verification

Verify, when relevant:

- entrance, exit, hover, focus, loading, success, error, and empty states;
- interruption and repeated interaction;
- mobile and touch behavior;
- keyboard and screen-reader behavior;
- `prefers-reduced-motion` behavior;
- runtime cleanup after unmount or route change;
- performance on realistic content and lower-powered devices.

Report source implementation, static checks, runtime verification, prior evidence, and unverified areas separately.
