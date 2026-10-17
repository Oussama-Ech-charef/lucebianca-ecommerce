---
name: diagnosing-bugs
description: Diagnose difficult bugs through a tight feedback loop, explicit hypotheses, safe evidence collection, and root-cause analysis.
---

# Diagnosing Bugs

Build a reliable pass/fail signal before changing code. A focused reproduction, regression test, or diagnostic command is more valuable than broad speculation. Move through the phases in order and skip a phase only when the reason is explicit.

## Use When

- A bug is reproducible or has a measurable symptom.
- A performance regression, flaky test, integration failure, or production issue needs root-cause analysis.
- A previous fix treated a symptom without preventing recurrence.

Do not use this skill to speculate from an incomplete description when a small piece of evidence would materially change the diagnosis.

## Inputs

- Exact symptom and expected behavior.
- Environment, version, route, data, and reproduction steps.
- Logs, traces, stack traces, screenshots, or failing tests.
- Recent changes and relevant configuration.

## Method

1. **Redact:** Remove secrets, tokens, personal data, credentials, and sensitive headers from all evidence.
2. **Reproduce:** Build a minimal, repeatable case that fails for the reported reason.
3. **Minimize:** Reduce inputs, dependencies, timing, and code paths until the failure is isolated.
4. **Hypothesize:** State plausible causes and the evidence that would distinguish them.
5. **Instrument:** Add temporary, safe observability or controlled comparisons when evidence is insufficient.
6. **Narrow:** Test one hypothesis at a time and preserve the feedback signal.
7. **Fix:** Change the root cause with the smallest safe implementation.
8. **Regress:** Add or update a test, check, or durable reproduction.
9. **Verify:** Re-run the original case, nearby edge cases, and relevant broader checks.

## Safety

- Do not paste real secrets into reports, tests, tickets, or prompts.
- Avoid destructive production investigation.
- Use read-only diagnostics first.
- Do not disable security controls to make a reproduction easier without explicit authorization and a safe environment.
- Remove temporary instrumentation unless it is intentionally retained and documented.

If the available evidence cannot distinguish the hypotheses, say so and request the smallest missing evidence.

## Output

Report the symptom, reproduction, evidence, hypotheses considered, root cause, fix, regression protection, verification results, and remaining uncertainty.
