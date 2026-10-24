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
8. Infer the project's actual conventions from repeated evidence before proposing implementation choices.
9. Record conventions, unknowns, risks, and exact commands that can verify the work.

## Scope and Evidence Requirements

- Report the exact paths used; never infer `.agent` or another directory name.
- State whether hidden, ignored, generated, or symlinked items were included.
- Do not claim a test or command ran unless its command, scope, environment, and result are recorded.
- Separate observed facts from assumptions and recommendations.
- If a count or claim cannot be verified, mark it unverified and explain why.

## Convention Discovery

Treat the codebase's existing behavior as the source of truth for style and architecture. Before introducing a pattern, inspect representative neighboring files and project-owned tooling.

- Record a convention only when there are at least three consistent examples, no meaningful rival, and the choice is not merely a framework default or formatter-owned behavior.
- Classify each candidate as `Pattern`, `Conflict`, `Default`, `No signal`, `Tooling-owned`, or `Already recorded`.
- Report evidence paths and competing patterns for conflicts. Do not silently choose a preferred style while the codebase remains mixed.
- Record deliberate architectural absences when they affect future work, but do not treat an unused feature as proof of a rule.
- Load framework-specific skills only after the framework is confirmed from project evidence or explicitly requested by the user.

## Output

Provide a concise project map, affected areas, confirmed facts, inferred conventions with evidence, assumptions, risks, and recommended next step.

Do not assume a dependency is actually used merely because it appears in a manifest.
