---
trigger: always_on
type: security_rule
---

# Security and Privacy

## Validation and Authorization

- Validate inputs at system boundaries.
- Separate authentication from authorization.
- Verify resource ownership before reading, changing, or deleting data.
- Never treat a hidden UI button as security.

## Data

- Do not expose secrets or personal data without a need.
- Never log passwords, tokens, or sensitive content.
- Define retention for sensitive data.
- Apply least privilege.

## Files

- Validate type, size, and extension.
- Do not make private files public by default.
- Separate stored filenames from user-provided filenames.
- Prevent path traversal.

## Web and APIs

- Use CSRF protection where applicable.
- Prevent XSS through appropriate escaping and sanitization.
- Use HTTPS in production.
- Rate-limit sensitive endpoints.
- Return errors that do not reveal internal details.

## AI

- Do not send sensitive data to an external provider without authorization.
- Separate system instructions from user input.
- Verify RAG sources.
- Do not treat model output as truth without validation.

## Security Evidence

- Distinguish static code evidence from runtime, browser, network, OS, and deployment evidence.
- Do not claim effective permissions, public exposure, exploitability, or compromise without checking the required environment.
- Report conditional risks with the conditions that make them applicable.
- Keep evidence status, risk severity, and operation type as separate fields.
- Preserve UTF-8 text and report encoding problems separately from security findings.

