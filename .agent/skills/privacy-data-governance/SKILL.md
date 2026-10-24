---
name: privacy-data-governance
description: Use when a project handles personal, health, identity, financial, or other sensitive data and the task requires privacy analysis, data classification, access rules, retention, minimization, or auditability.
---

# Privacy and Data Governance

Analyze how sensitive data is collected, stored, processed, exposed, retained, exported, and deleted. Keep the analysis grounded in repository evidence and distinguish technical controls from legal or organizational obligations.

## Workflow

1. Inventory data categories, fields, sources, consumers, storage locations, logs, exports, backups, and third-party transfers.
2. Classify sensitivity and document the purpose and minimum data needed for each flow.
3. Map subjects, roles, trust boundaries, authorization paths, and administrative access.
4. Check consent or notice points, retention/deletion behavior, correction/export paths, audit trails, redaction, encryption, and secret handling where applicable.
5. Separate confirmed implementation evidence from assumptions, policy decisions, and jurisdiction-dependent questions.
6. Recommend the smallest concrete controls: minimization, least privilege, redaction, retention limits, access logging, key separation, and safe test fixtures.

## Boundaries

- Do not invent legal compliance, consent, retention periods, or data-subject rights; mark them as requirements to confirm with the owner or counsel.
- Do not expose real personal or medical data in reports, screenshots, test fixtures, logs, or commands.
- Do not delete, migrate, export, anonymize, or change access controls without explicit authorization.
- For a technical vulnerability report, use `security`; for explicit abuse-path modeling, also use `security-threat-model`.

## Output

Report data inventory, lifecycle flows, access matrix, confirmed controls, gaps, assumptions, open decisions, risk, and verification steps. State which claims were read from source, observed at runtime, or remain unverified.
