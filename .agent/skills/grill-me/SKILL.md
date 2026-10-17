---
name: grill-me
description: Stress-test a plan, design, or architecture through focused questions before implementation.
---

# Grill Me

Use this skill for broad, ambiguous, high-risk, or architecture-heavy work where hidden assumptions could cause rework. It is intentionally user-facing: its purpose is to align the user and Agent before implementation, not to create unnecessary friction for small tasks.

## Use When

- The plan has several materially different design branches.
- The request affects architecture, data, security, public APIs, or irreversible operations.
- Acceptance criteria or domain terminology are unclear.
- The user wants a design or plan challenged before execution.

Skip it for small, obvious, reversible changes unless the user asks for a deep review.

## Inputs

- Goal, current plan, design, or decision.
- Project context, constraints, terminology, and existing decisions.
- Known users, workflows, integrations, and failure modes.

## Method

1. State the current goal, assumptions, scope, and decision points.
2. Build a decision tree of questions that can materially change the design or plan.
3. Ask one concise question or small question group at a time.
4. Explore edge cases, failure modes, permissions, data boundaries, operational constraints, and non-goals.
5. Inspect the codebase to answer questions that can be answered from evidence.
6. Use the project’s terminology and distinguish facts, assumptions, and choices.
7. Record resolved decisions and the reasoning behind them.
8. Stop once important branches are resolved or the user chooses a direction.

## Question Quality

- Ask questions that change scope, architecture, data, security, acceptance, or operations.
- Challenge invented numbers, thresholds, data limits, roles, and acceptance criteria before they become commitments.
- Prefer concrete scenarios over abstract preference questions.
- Do not ask for information already available in the project.
- Do not hide a recommendation behind endless questions.
- If an answer is low-risk and reversible, make a clearly stated assumption instead.

## Output

Return the clarified goal, resolved decisions, remaining questions, assumptions, risks, updated scope, and recommended next step.

Do not turn every small task into an interrogation. Use the smallest amount of questioning that prevents meaningful downstream risk.
