---
name: web-design-guidelines
description: Audit web interfaces for practical design, accessibility, interaction, and usability problems.
---

# Web Design Guidelines

Use this skill for UI reviews, visual QA, accessibility reviews, or design-system consistency checks. It is an audit aid, not a replacement for product context, user research, manual assistive-technology testing, or project-specific design rules.

## Use When

- The user asks to review a page, component, or interface.
- A UI change needs visual, responsive, accessibility, or interaction verification.
- A project needs a repeatable design-system compliance pass.

## Inputs

- Page, component, route, screenshot, design, or file pattern under review.
- Target viewports, supported browsers, locales, and interaction states.
- Existing design tokens, component conventions, and accessibility requirements.

If a current external guideline set is required, request or fetch it only through an authorized and trusted source. Record the guideline version or retrieval date.

## Review Areas

- Semantic structure and readable content.
- Typography, spacing, color, contrast, and hierarchy.
- Keyboard navigation, focus, labels, and screen-reader behavior.
- Responsive layout, touch targets, and overflow.
- Loading, empty, success, error, and disabled states.
- Interaction feedback, motion, performance, and perceived speed.
- Consistency with the project’s existing design system.

## Method

1. Understand the page’s purpose, primary action, user roles, and content.
2. Review structure and states before visual polish.
3. Check the interface at relevant widths and keyboard paths.
4. Inspect semantic markup, focus behavior, labels, errors, and announcements.
5. Check content length, locale, RTL, reduced motion, and contrast when relevant.
6. Check loading, empty, success, error, disabled, and permission states.
7. Separate objective defects from aesthetic preferences.
8. Prioritize findings by user impact, accessibility, frequency, and effort.

## Output

Report findings as `file:line` or component-level locations when possible. Include severity, evidence, affected user, recommendation, and verification method. State viewport, browser, locale, and assistive technology limitations.

Do not replace project intent with generic aesthetics, and do not treat a guideline as a substitute for testing with real users or assistive technology.
