---
name: tailwindcss-development
description: Use when a project already uses Tailwind CSS and the task involves responsive layouts, UI components, utility classes, dark mode, spacing, or Tailwind configuration. Detect the installed Tailwind version and existing build method before editing; do not introduce Tailwind or a new build tool without approval.
---

# Tailwind CSS Development

Use this skill only after project discovery confirms Tailwind CSS or the user explicitly requests it.

## Before Editing

1. Detect the Tailwind version from package manifests, lockfiles, generated assets, configuration, or documented build commands.
2. Read the existing CSS entry point, configuration, component conventions, and build scripts.
3. Preserve the project's current version and build method. Do not migrate v3 to v4, add Node.js, or replace a standalone/compiler workflow without explicit approval.
4. Check target pages at desktop and mobile widths when rendered verification is available.

## Implementation Rules

- Match existing utility ordering, tokens, breakpoints, dark-mode strategy, and component patterns.
- Use responsive behavior deliberately: preserve readable content, usable controls, keyboard focus, and appropriate overflow on narrow screens.
- Prefer `gap` for spacing between flex/grid siblings when it expresses the relationship clearly; use margins when they represent external spacing or the existing project convention requires them.
- Use utilities supported by the detected Tailwind version. Do not copy v4-only syntax into a v3 project or deprecated utilities into a v4 project.
- Keep repeated UI patterns consistent. Extract a component only when the project already has a component system or the repetition creates a clear maintenance boundary.
- Preserve semantic HTML, accessible names, visible focus states, and reduced-motion behavior when transitions are present.

## Verification

Report separately whether the source classes exist, the stylesheet/build check passed, and the rendered layout was verified in a browser. Do not call the interface responsive or visually correct without a relevant rendered check.

If the build or browser is unavailable, state the exact unverified scope and do not compensate by claiming that class names guarantee the final output.
