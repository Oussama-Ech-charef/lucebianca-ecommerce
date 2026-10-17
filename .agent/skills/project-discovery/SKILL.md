---
name: project-discovery
description: Build an evidence-based map of an unfamiliar project before planning or changing it.
---

# Project Discovery

## Method

1. Locate and resolve the workspace root, Agent configuration path, and project root.
2. Confirm whether the Agent configuration is nested, adjacent to, or inside the project.
3. Read the README, contribution rules, configuration, package manifests, entry points, and relevant scripts.
4. Inventory source, tests, documentation, infrastructure, generated files, and sensitive files using a reproducible filesystem method.
5. Reconcile file, directory, extension, and area totals before reporting them.
6. Detect the languages, frameworks, data stores, build tools, and external services.
7. Trace the requested feature through its UI, API, domain, persistence, and test layers.
8. Record conventions, unknowns, risks, and exact commands that can verify the work.

## Scope and Evidence Requirements

- Report the exact paths used; never infer `.agent` or another directory name.
- State whether hidden, ignored, generated, or symlinked items were included.
- Do not claim a test or command ran unless its command, scope, environment, and result are recorded.
- Separate observed facts from assumptions and recommendations.
- If a count or claim cannot be verified, mark it unverified and explain why.

## Output

Provide a concise project map, affected areas, confirmed facts, assumptions, risks, and recommended next step.

Do not assume a dependency is actually used merely because it appears in a manifest.
