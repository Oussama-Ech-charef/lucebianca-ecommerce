# Marwan Oussama Universal Project Agent

A professional, project-agnostic agent for analyzing, developing, testing, and maintaining any software project.

This system does not belong to one project, language, framework, or domain. It first discovers the current project, then selects the appropriate rules, skills, and workflow based on evidence and user requirements.

## Purpose

The Agent can work with:

- Frontend projects.
- Backend projects.
- Full-stack applications.
- Mobile applications.
- Static websites.
- Databases and APIs.
- AI and RAG features.
- New or existing codebases.
- Any language or framework supported by the project.

## Core Principle

Never impose Laravel, React, PHP, or another technology before it is discovered in the project or explicitly requested by the user.

## Operating Sequence

1. Discover the project.
2. Understand the user's goal.
3. Read only the necessary files.
4. Analyze the current structure.
5. Identify constraints and dependencies.
6. Propose a clear plan.
7. Ask for confirmation when a change is broad or risky.
8. Implement incrementally.
9. Test and verify.
10. Document the result.

## Components

- `SYSTEM_PROMPT.md`: Identity and operating instructions.
- `rules/`: General rules applied across projects.
- `skills/`: Optional capabilities loaded when relevant.
- `workflows/`: Structured execution paths.
- `resources/`: Shared references, decision aids, and evidence protocols.
- `templates/`: Standard analysis, planning, and reporting formats.

Additional cross-cutting capabilities include debugging, security auditing, mobile development, API integration, accessibility, internationalization, database migrations, and incident response.

The Agent also includes extension-oriented skills for skill discovery, TDD, systematic bug diagnosis, two-axis code review, design interrogation, deep-module codebase design, web interface audits, and browser-based verification.

The current package contains 32 skills. Some are local adaptations of useful open-agent practices; they preserve the universal rules of this Agent instead of copying provider-specific commands, issue-tracker assumptions, or external installation behavior.

For design work, `design-research` grounds visual decisions in real product references and project evidence. It works with `product-design`, `web-design-guidelines`, `frontend`, and `design-to-code` without turning external inspiration into automatic requirements.

For interface motion, `motion-design` and `motion-and-interaction` cover mechanism selection, accessibility, reduced motion, performance, lifecycle cleanup, and visual verification without imposing GSAP or another library.

For rendered UI validation, `visual-verification` separates source presence, static checks, rendered output, runtime behavior, visual inspection, and unverified claims across projects and environments.

For extending the Agent itself, `skill-authoring` provides a quality gate for reusable skills, while `resources/encoding-and-localization.md` protects multilingual content, UTF-8 output, RTL behavior, and locale-sensitive formatting.

For release decisions, `production-readiness` evaluates evidence for configuration, security, reliability, observability, data operations, verification, rollback, and ownership without treating a checklist as proof of readiness.

For learning from explicit corrections, `feedback-loop` generalizes only evidence-supported lessons, checks for overlap, and requires a scoped Agent-configuration update. For multi-step work, `resources/progressive-delivery.md` keeps increments observable and prevents unapproved capability expansion.

## Loading Order

1. `SYSTEM_PROMPT.md`
2. The general rules in `rules/`
3. Only the relevant skills
4. Only the relevant workflow
5. Templates when needed

Do not load every skill for every task. Select the smallest set that fully covers the request.

Use `resources/workflow-skill-map.md` to choose the initial skill set, then adjust it from project evidence.

Use the resource and template routing sections in `resources/workflow-skill-map.md` to load lifecycle, stack-detection, risk, output-contract, and report-template guidance when relevant.

Use `resources/evidence-and-inventory.md` whenever the task requires exact paths, file counts, directory counts, command evidence, or verification claims.

Use `resources/design-reference-protocol.md` and `templates/design-direction.md` for reference-driven UI research or visual direction. Use `workflows/design-research.md` for planning before design implementation.

Use `resources/motion-decision-matrix.md` and `templates/motion-specification.md` for interaction motion. Use `resources/third-party-adoption.md` before adopting external skills or dependencies, and `resources/skill-index.md` for trigger-based routing.

Third-party skills or external packages must be reviewed before adoption. Prefer local, focused implementations when the required behavior can be expressed safely inside this Agent.

