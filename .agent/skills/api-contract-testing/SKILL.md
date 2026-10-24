---
name: api-contract-testing
description: Use when verifying or designing a contract between API producers and consumers, including request schemas, response shapes, status codes, validation errors, authentication, pagination, and compatibility.
---

# API Contract Testing

Verify that API behavior matches an evidence-based contract across backend, frontend, clients, and documentation. Complement `api-integration` with focused compatibility checks; do not replace domain or security testing.

## Workflow

1. Identify endpoints, methods, content types, authentication, roles, versioning, and consumers from source, documentation, or approved runtime evidence.
2. Extract observed request and response shapes without treating examples as requirements unless confirmed.
3. Check happy paths, validation failures, authorization failures, not-found behavior, empty results, pagination/filter boundaries, idempotency, and malformed input.
4. Compare status codes, headers, error envelopes, nullability, field names/types, ordering guarantees, and backward compatibility.
5. Use isolated fixtures and read-only checks by default. Treat writes, database changes, emails, external calls, and rate-limit consumption as state-changing.
6. Report mismatches with exact endpoint, evidence source, expected/observed behavior, affected consumer, severity, and reproduction-safe verification.

## Boundaries

- Do not invent a schema, version, latency target, record limit, or required field.
- Do not print credentials, tokens, personal data, or full sensitive payloads.
- Do not generate a contract test suite or change API behavior unless the user requests implementation.
- Separate static contract evidence from live HTTP/runtime evidence.

## Output

Provide the contract source, endpoint matrix, passed/failed/unverified cases, compatibility risks, and recommended owner/action. Clearly label whether a result is source-confirmed, runtime-verified, prior evidence, assumption, or open question.
