---
name: accessibility-testing
description: Use when performing runtime or rendered accessibility verification for a web or mobile interface, including keyboard access, focus, semantics, labels, contrast, zoom, reduced motion, and responsive use.
---

# Accessibility Testing

Perform evidence-based accessibility checks across the actual rendered interface. Complement `accessibility-internationalization` with execution and verification; do not claim full compliance from a checklist or automated scan alone.

## Workflow

1. Identify in-scope routes, user flows, locales, viewports, input methods, and assistive-technology assumptions.
2. Inspect semantic structure, landmarks, headings, labels, names, descriptions, focus order, keyboard traps, skip paths, live regions, errors, and dialogs.
3. Verify keyboard-only interaction, visible focus, zoom/reflow, contrast, target sizing, reduced-motion behavior, and responsive layout where relevant.
4. Use automated checks as signals, then manually verify meaningful user flows and edge states: loading, empty, error, validation, disabled, and authenticated views.
5. Record exact evidence, affected users, severity, reproducibility, and the smallest remediation.

## Boundaries

- Do not claim WCAG or legal conformance without defined scope, version, level, environment, and adequate testing evidence.
- Do not treat a single browser, automated scan, or screenshot as complete accessibility verification.
- Do not change UI code unless implementation is explicitly requested.
- Protect personal and medical information in screenshots and reports.

## Output

Report routes and environments tested, methods, passed/failed/skipped/unverified checks, findings with locations, user impact, remediation, and known coverage gaps.
