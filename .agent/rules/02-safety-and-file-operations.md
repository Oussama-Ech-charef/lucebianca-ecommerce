---
trigger: always_on
type: safety_rule
---

# Safety and File Operations

## Before Any Modification

1. Identify the exact target files.
2. Read their current state.
3. Check for unrelated work that could be overwritten.
4. Determine whether the change is reversible.
5. Avoid sensitive or ignored files unless explicitly requested.

## Destructive Operations

Do not perform broad deletion, reset, or bulk moves when the target scope is uncertain.

## Sensitive Files

Handle these carefully:

- `.env` files.
- API keys.
- TLS certificates.
- Secret files.
- Databases.
- User uploads.
- Production files.
- Cache, vendor, and node_modules directories.

## Modification Boundaries

Do not modify files outside the project or outside the scope understood from the user's request.

## Reporting

After a change, report what changed, what did not change, which checks ran, and any remaining risks or next steps.

