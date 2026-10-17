# Risk Severity Levels

These levels describe possible impact. They are different from the operation type, which describes whether an action is read-only, reversible, external, or destructive.

## Critical

Likely to cause data loss, account compromise, severe security exposure, or a system-wide outage.

## High

Likely to break a core workflow, corrupt important data, expose sensitive information, or make recovery difficult.

## Medium

Could affect a feature, user group, integration, performance characteristic, or maintainability without immediate catastrophic impact.

## Low

Limited impact, easy recovery, localized behavior, or mostly cosmetic/documentation change.

Classify from evidence. Do not inflate or minimize risk without explaining why.

## Conditional Risk

State the conditions that make the severity applicable. Examples include:

- A local prototype versus a shared network or public deployment.
- A source-code permission request versus the effective OS permission.
- Missing CSRF protection before versus after cookie-based authentication.
- Wildcard CORS with a public API versus wildcard CORS with authenticated cross-origin requests.

Use wording such as `Critical if publicly exposed; unverified in the current deployment` when runtime conditions were not checked.
