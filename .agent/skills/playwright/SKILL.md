---
name: playwright
description: Use when the task explicitly requires Playwright CLI or terminal browser automation, Playwright snapshots/traces, or a repeatable Playwright harness. For generic browser verification, prefer agent-browser. Keep it separate from application runtime dependencies.
---

# Playwright Browser Verification

Use this skill for evidence-based browser verification of a web application when Playwright-specific automation or artifacts are needed. It complements `agent-browser` and `visual-verification`; it is not the default for every browser-visible task.

## Scope

- Verify an existing web application or local server.
- Exercise user-visible flows, forms, navigation, validation, empty states, permissions, and responsive layouts.
- Capture only the artifacts needed to support the result: snapshots, screenshots, console/network evidence, or traces.
- Keep browser tooling outside the application's production/runtime stack. Do not introduce Node.js, Playwright, or another framework into the application unless the user explicitly approves it.

Do not use this skill for generic browser navigation when `agent-browser` is sufficient, static source inspection alone, backend-only tests, or creating a full test suite unless the user explicitly requests that deliverable.

## Required preflight

1. Confirm the project root, target URL, server command, and test data policy.
2. Check whether the available Playwright CLI or an existing project browser harness is already installed.
3. If the tool is missing, report the prerequisite and ask before installing packages or changing the project.
4. Never expose credentials, tokens, cookies, medical data, or other sensitive values in commands, screenshots, logs, or reports.
5. Prefer a disposable local environment and non-production data. Do not create accounts, rooms, external resources, or persistent test data without explicit approval.

## Browser workflow

1. Start or reuse the approved local server without changing application files.
2. Open the target page.
3. Capture a fresh DOM/accessibility snapshot before using element references.
4. Interact using stable labels, roles, names, or fresh references; avoid guessing selectors when evidence is available.
5. Capture another snapshot after navigation, modal/menu changes, form submission, or other substantial DOM changes.
6. Check the expected visual and behavioral result at the required desktop/mobile viewports.
7. Review browser console errors and relevant failed network requests.
8. Stop the server and clean temporary artifacts when the operation is complete, unless the user asked to keep it running.

## Evidence and reporting

Report separately:

- source/static checks;
- browser/runtime checks actually executed;
- visual checks and viewport sizes;
- passed, failed, skipped, and unavailable checks;
- exact URLs/routes and test data policy;
- artifacts created and their locations;
- assumptions and limitations.

Do not call a feature operational, browser-compatible, or visually verified unless the corresponding browser evidence was actually collected. Distinguish a browser-tool failure from an application failure.

## Safety rules

- Do not run destructive actions, migrations, seeding, purchases, messages, or external integrations without explicit authorization.
- Treat any action that changes persistent data as state-changing even when initiated through the browser.
- For authenticated checks, use approved test accounts and redact secrets.
- For medical or personal-data projects, use synthetic fixtures only and avoid recording sensitive values in artifacts.

## External adaptation note

This skill is a local adaptation of the Apache-2.0-licensed `playwright` skill from `skills2/skills/.curated/playwright/SKILL.md`. Provider-specific shell paths and global-install assumptions were removed so routing remains project-agnostic.
