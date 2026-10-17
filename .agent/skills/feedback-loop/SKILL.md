---
name: feedback-loop
description: Convert explicit user corrections, failed verification findings, or repeated Agent mistakes into proposed reusable guidance without silently changing the Agent configuration.
---

# Feedback Loop

Use this skill when the user asks the Agent to learn from a correction, preserve a lesson, update its rules, or prevent a demonstrated mistake from recurring.

Do not use it for an ordinary project bug fix unless the user also wants the Agent's reusable guidance changed.

## Workflow

1. Capture the correction and separate the user's intended outcome from the project-specific implementation.
2. Identify the evidence, the affected workflow or skill, and the exact behavior that was wrong or missing.
3. Search the existing rules, skills, resources, and workflows for overlap or contradiction before proposing anything new.
4. Generalize only the lesson supported by the evidence. Keep paths, providers, frameworks, names, and one-off preferences out of universal guidance unless they are explicitly part of the Agent's scope.
5. Choose the smallest target:
   - an existing rule for an always-active constraint;
   - an existing skill for task-specific orchestration;
   - an existing resource for reusable method or reference material;
   - a new file only when no suitable target exists.
6. Present the proposed lesson, target file, scope, and conflict analysis before changing Agent configuration unless the user explicitly authorized the update.
7. After an approved update, validate front matter, links, routing entries, UTF-8 integrity, and consistency with the existing system. Report what was changed and what remains unverified.

## Rules

- Never turn an assumption into a permanent rule without evidence or explicit user approval.
- Never duplicate an existing rule or resource; strengthen the existing source when that is sufficient.
- Never copy project-specific behavior into a project-agnostic rule.
- Never claim that a future mistake is impossible. Report the rule added and the verification performed.
- Preserve the user's current scope and authorization. Learning from a correction does not authorize unrelated refactoring.

## Related guidance

- For skill structure and overlap review, apply [`skill-authoring`](../skill-authoring/SKILL.md).
- For evidence and reporting, read [`output-contract`](../../resources/output-contract.md).
- For the Agent's execution states and handoff, read [`agent-lifecycle`](../../resources/agent-lifecycle.md).
