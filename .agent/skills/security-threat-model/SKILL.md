---
name: security-threat-model
description: Use only when the user explicitly asks for a repository-grounded threat model, abuse-path analysis, trust-boundary review, or AppSec threat modeling of a codebase or path.
---

# Repository Threat Modeling

Produce a concise, evidence-based threat model for the requested repository or project path. This is narrower than the general `security` skill: it models attacker goals and abuse paths across trust boundaries rather than performing a broad security audit or code review.

## Trigger boundary

Activate only for an explicit threat-modeling request, such as “threat model this repository,” “enumerate abuse paths,” or “perform AppSec threat modeling.” Do not activate for a normal project summary, architecture description, implementation plan, or generic security review.

## Workflow

1. Identify the repository root, in-scope paths, runtime entrypoints, deployment model, users, and external integrations from filesystem and source evidence.
2. Separate runtime components from tests, development tooling, CI, examples, and generated artifacts.
3. Map concrete trust boundaries, protocols, authentication, authorization, validation, encryption, rate limits, and logging where evidence exists.
4. Identify high-value assets: credentials, personal or medical data, authentication state, integrity-critical records, availability-critical services, configuration, logs, and build/deployment artifacts.
5. Describe realistic attacker capabilities and explicitly state non-capabilities to avoid inflated severity.
6. Enumerate a small set of concrete abuse paths tied to assets and boundaries. Prefer exfiltration, privilege escalation, unauthorized changes, impersonation, denial of service, and integrity compromise over generic checklist items.
7. Rank each path with likelihood, impact, priority, conditions, affected users/assets, existing controls, and recommended mitigations.
8. Separate confirmed evidence from assumptions, open questions, and conditional deployment risks.
9. Ask no more than three targeted questions when missing context can materially change scope or severity. Pause before finalizing if the user has not answered those questions.
10. Run a quality check: every discovered entrypoint and trust boundary is covered, runtime and non-runtime concerns are separated, and no secret or unsafe exploit instruction is included.

## Required output

Use these sections:

- Scope and system model
- Confirmed architecture and entrypoints
- Trust boundaries
- Assets and data sensitivity
- Attacker capabilities and non-capabilities
- Prioritized abuse paths
- Existing mitigations versus recommended mitigations
- Assumptions and open questions
- Verification limitations

Each finding should include an identifier, evidence status, severity, exact location when available, conditions, affected assets/users, recommendation, and verification method. Do not claim a control is effective in production without runtime or deployment evidence.

Do not automatically write a report file unless the user asks for a file deliverable. If a file is requested, use the project's documentation conventions and report the exact path.

## Safety rules

- Read-only analysis is the default.
- Do not exploit vulnerabilities, alter data, create credentials, contact external services, or modify deployment configuration without explicit authorization.
- Never print or store secrets, personal data, patient data, tokens, or private keys.
- Treat medical, identity, and authentication data as sensitive by default and prefer synthetic examples.

## External adaptation note

This skill is a local adaptation of the Apache-2.0-licensed `security-threat-model` skill from `skills2/skills/.curated/security-threat-model/SKILL.md`. The source's provider-specific reference paths were condensed into this Agent's evidence, safety, and reporting conventions.
