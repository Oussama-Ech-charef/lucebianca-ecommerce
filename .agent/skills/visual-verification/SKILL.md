---
name: visual-verification
description: Verify rendered web or application interfaces against intended behavior, layout, states, accessibility, responsiveness, and motion without treating source inspection as runtime proof.
---

# Visual Verification

Use this skill when a UI change, design direction, animation, responsive behavior, or browser-visible feature needs verification.

## Verification Boundary

Separate these claims:

- **Implemented in source:** The relevant code or styles exist on disk.
- **Statically checked:** A named syntax, lint, type, build, or pattern check passed.
- **Rendered:** The route or surface was opened in the relevant runtime.
- **Runtime verified:** The intended interaction was exercised and observed.
- **Visual verified:** The rendered result was inspected at the required viewport and state.
- **Unverified:** The relevant runtime or visual check was not performed.

Never call a UI operational, polished, responsive, accessible, or browser-compatible from source inspection alone.

## Method

1. Resolve the project, route or surface, runtime, browser, viewport, locale, and theme.
2. Inspect existing design tokens, component conventions, and acceptance criteria.
3. Verify the primary happy path and the relevant edge states: loading, empty, error, success, disabled, permission, and long content.
4. Check desktop and mobile widths, overflow, touch targets, keyboard focus, and reduced-motion behavior when relevant.
5. Inspect visual hierarchy, alignment, spacing, typography, contrast, content wrapping, and unexpected layout shifts.
6. Capture or record reproducible evidence for each observed result.
7. Separate defects from preferences and prioritize by user impact, accessibility, frequency, and effort.

## Safe Verification

- Use synthetic or non-sensitive data.
- Do not submit real medical, financial, personal, or production data.
- Do not modify project files during a read-only visual audit.
- Do not claim a viewport, browser, locale, or assistive-technology result that was not actually tested.
- If the application cannot run, report the blocker and use source inspection or approved fixtures only as limited evidence.

## Output

Report:

- route/surface and exact environment;
- viewport, browser, locale, theme, and assistive technology when relevant;
- checks performed and exact method;
- observed findings with severity and location;
- implementation, static, rendered, runtime, visual, and unverified statuses;
- screenshots or other evidence when available;
- limitations and next actions.
