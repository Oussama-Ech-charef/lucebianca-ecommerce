---
name: backend
description: Design or implement reliable backend behavior while preserving security and domain boundaries.
---

# Backend

Discover the language, framework, entry points, authentication, authorization, validation, error handling, jobs, transactions, and external integrations.

Keep controllers or handlers thin when domain services are appropriate. Validate untrusted input, enforce ownership and authorization, separate transport from business logic, use transactions deliberately, return correct status codes, and avoid exposing internal details in production errors.

For state-changing behavior, protect invariants with the strongest mechanism supported by the project: database constraints, atomic updates, transactions, or explicit locks. Use parameterized queries and allow-lists for dynamic identifiers. Give outbound calls explicit connection and response timeouts, retry only operations that are safely idempotent, and handle expected failure responses explicitly.

Verify success, validation failure, authorization failure, missing resources, integration failure, and retry behavior where relevant.
