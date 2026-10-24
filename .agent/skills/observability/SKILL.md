---
name: observability
description: Use when designing, reviewing, or verifying logs, metrics, traces, health checks, alerts, diagnostics, and operational visibility for an application or service.
---

# Observability

Evaluate whether operators can detect, understand, and safely investigate important system behavior. Cover logs, metrics, traces, health/readiness checks, alerts, dashboards, and incident context without assuming a specific vendor.

## Workflow

1. Identify critical user journeys, dependencies, failure modes, owners, environments, and operational boundaries.
2. Inventory existing logs, metrics, traces, health endpoints, alerts, correlation IDs, dashboards, retention, access controls, and runbooks.
3. Check signal quality: useful dimensions, timestamps, causal context, error classification, latency, saturation, retries, queue/backlog, and dependency failures.
4. Check safety: no secrets or sensitive payloads, bounded cardinality, redaction, sampling, retention, access control, and alert-noise limits.
5. Map important failures to detection, diagnosis, response, and recovery evidence. Distinguish source configuration from active production behavior.
6. Recommend the smallest missing signal or operational control and define how it will be verified.

## Boundaries

- Do not create alerts, dashboards, external integrations, or production traffic without explicit authorization.
- Do not claim monitoring is active because configuration exists; runtime and deployment evidence are separate.
- Do not log credentials, tokens, patient data, full request bodies, or unnecessary personal information.
- For release decisions, combine this skill with `production-readiness` and `quality-assurance`.

## Output

Report critical scenarios, current signals, evidence status, gaps, risks, recommended signals/alerts, ownership assumptions, and verification or rollback steps.
