---
name: performance-audit
description: Use when measuring or diagnosing web, API, database, build, or runtime performance and the task requires evidence-based bottleneck analysis or performance verification.
---

# Performance Audit

Measure the relevant user or system path before recommending optimization. Keep performance claims tied to a defined environment, workload, sample, and metric.

## Workflow

1. Define the user journey or operation, environment, device/viewport, network conditions, dataset, concurrency, and success metric.
2. Establish a baseline using available browser, HTTP, runtime, database, or build measurements.
3. Separate frontend loading/rendering, network/API, backend execution, database queries, external services, and infrastructure effects.
4. Identify the highest-impact bottlenecks and confirm them with repeatable measurements rather than intuition.
5. Check regressions, cache behavior, slow/error paths, mobile constraints, and accessibility tradeoffs where relevant.
6. Recommend the smallest change with an explicit verification method and rollback consideration.

## Boundaries

- Do not invent targets such as latency thresholds, record counts, traffic, or device support; ask for them or label them as assumptions.
- Do not run load tests, stress tests, production probes, or expensive external services without explicit authorization.
- Do not optimize before measuring, and do not treat a local result as production evidence.
- Never include secrets or sensitive payloads in traces and artifacts.

## Output

Report scope, environment, method, baseline, measurements, variance, bottleneck evidence, passed/failed/unavailable checks, recommendations, and limitations. Distinguish measured results from estimates.
