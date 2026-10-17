---
name: codebase-design
description: Improve codebase structure by designing deep, testable modules with small interfaces and clear seams.
---

# Codebase Design

Use this skill when a codebase has shallow modules, excessive coupling, repeated orchestration, unclear responsibilities, or difficult testing seams. This is a design and refactoring assessment, not permission to rewrite the project.

## Use When

- A change repeatedly crosses many modules for a small behavior.
- Tests require excessive setup or reach into implementation details.
- Callers know too much about data access, providers, or orchestration.
- A stable seam could reduce coupling or make future changes local.

## Inputs

- A focused module, subsystem, or recent change area.
- Project glossary, ADRs, tests, and recent history when available.
- Known pain points, change frequency, and acceptance constraints.

## Principles

- A module should hide substantial behavior behind a small, understandable interface.
- An interface includes usage rules, invariants, error modes, configuration, and performance expectations.
- A seam should allow behavior to change without editing every caller.
- Prefer locality and explicit dependencies over scattered knowledge.
- Use the deletion test: if removing a module changes many unrelated areas, its responsibility may be poorly placed.
- Treat an interface as more than a type signature: include invariants, ordering, errors, configuration, and performance expectations.
- Prefer one real seam over speculative abstractions; add indirection when it buys testability or change isolation.

## Method

1. Scope the analysis to the requested area and recent change hotspots.
2. Read domain terminology and relevant ADRs before proposing names or boundaries.
3. Trace callers, data flow, side effects, error paths, and tests.
4. Identify shallow modules, duplicated knowledge, unstable dependencies, and untested seams.
5. Propose at least two viable interface designs when the decision is significant.
6. Compare locality, coupling, testability, migration cost, performance, and operational risk.
7. Recommend the smallest improvement that solves the demonstrated problem.
8. Define incremental migration and verification steps.

## Output

Describe the architectural friction, evidence, affected modules, interface options, trade-offs, migration steps, verification plan, and decision to defer when no refactor is justified. Do not refactor merely to apply terminology or patterns.
