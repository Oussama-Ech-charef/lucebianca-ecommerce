---
name: production-readiness
description: Assess whether a project is ready for a safe release by checking configuration, security, reliability, observability, data operations, verification, and rollback evidence.
---

# Production Readiness

Use this skill for release-readiness reviews, pre-deployment checks, launch gates, or requests to determine what remains before shipping a project.

## Boundary

Default to a read-only assessment. Do not deploy, rotate secrets, change infrastructure, reset data, run migrations, or alter production systems unless the user explicitly authorizes the specific operation and the risk is confirmed.

Do not declare a project production-ready from a checklist alone. Require evidence appropriate to the project's stack, risk, environment, and release scope.

## Discovery

Inspect and report the evidence for:

- build, test, lint, type-check, and packaging commands;
- runtime, environment, configuration, and secrets handling;
- authentication, authorization, validation, and security controls;
- database migrations, backups, restore procedures, and data compatibility;
- logging, monitoring, alerting, health checks, and error reporting;
- dependency versions, licenses, vulnerabilities, and supply-chain controls;
- performance, capacity, timeouts, retries, and rate limits;
- accessibility, localization, responsive behavior, and critical user journeys;
- deployment, rollback, feature flags, maintenance mode, and ownership;
- documentation, runbooks, support, and incident response.

Only report items supported by current project or environment evidence. Mark missing evidence as unknown or unverified instead of assuming it is absent or correct.

## Readiness Classification

Classify each finding as:

- **Ready:** Evidence satisfies the defined release criterion.
- **Blocked:** A confirmed requirement or critical control is not satisfied.
- **Needs evidence:** The control may exist but was not verified.
- **Conditional:** Readiness depends on deployment, traffic, data, or configuration conditions.
- **Out of scope:** Explicitly excluded from this release.

Prioritize blockers by user impact, data sensitivity, security, reversibility, and recovery difficulty.

## Release Gate

Before approval, summarize:

- release scope and exact artifact or commit;
- confirmed acceptance criteria;
- checks actually executed, with command, scope, environment, result, and evidence;
- unresolved blockers and owners;
- rollback and recovery evidence;
- production-only assumptions;
- explicit approval still required.

Never convert recommendations, prior reports, or untested assumptions into release approval. Separate “ready to deploy” from “deployed successfully”.

## Output

Return a concise readiness decision, evidence table, blockers, risks, unverified areas, safe next actions, and the exact condition that would change the decision.
