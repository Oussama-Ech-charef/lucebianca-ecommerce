---
name: agent-browser
description: Verify web experiences through controlled browser navigation, interaction, extraction, and screenshots.
---

# Agent Browser

Use this skill when behavior depends on a real browser, rendered UI, navigation, forms, authentication state, responsive layout, or visual output. This skill describes the verification method; it does not install or authorize a browser automation package by itself.

## Use When

- Testing a public or local web route.
- Verifying a critical user journey, form, redirect, permission, or responsive layout.
- Capturing visual evidence or browser console/network failures.
- Reproducing a browser-only defect.

## Preconditions

- The target URL and environment are authorized.
- The required browser tool is already available or the user explicitly authorizes setup.
- Test credentials and data are safe to use.
- The expected journey and success criteria are known.

## Method

1. Define the URL, environment, account state, and user journey under test.
2. Record browser, viewport, route, locale, and starting state.
3. Navigate only to authorized pages and avoid exposing credentials or private data.
4. Interact with controls using their visible or semantic behavior.
5. Capture screenshots, console errors, network failures, and relevant outputs when useful.
6. Verify success, validation errors, loading, empty, permission, and failure states.
7. Repeat the smallest critical path when a failure occurs and preserve evidence.
8. Clean up temporary data or sessions when safe and authorized.

## Safety and Privacy

- Never include passwords, tokens, cookies, personal data, or authorization headers in screenshots or reports.
- Do not navigate to unauthorized targets or submit destructive actions without confirmation.
- Use dedicated test accounts and data where possible.
- Do not infer server-side correctness from UI appearance alone.

## Output

Report the journey, environment, browser and viewport, steps completed, evidence captured, failures, console or network findings, and untested areas.

Do not claim backend correctness from a browser result alone. Combine browser evidence with API, unit, integration, or build checks as appropriate.
