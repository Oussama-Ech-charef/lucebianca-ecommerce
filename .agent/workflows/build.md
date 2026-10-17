# Build Workflow

## Related Skills

`architecture`, with the domain skill such as `frontend`, `backend`, `database`, `mobile`, or `api-integration`, plus `tdd` or `codebase-design` when appropriate.

## Before

Read the affected files, local rules, architecture, tests, and conventions. Confirm the smallest change that satisfies the goal.

Perform a scope gate before editing:

1. List the confirmed behavior to implement.
2. Mark each planned change as required behavior, supporting change, or optional enhancement.
3. Identify any new capability that is not already present.
4. Ask for confirmation before implementing that capability when it materially expands the user-visible scope, architecture, dependencies, data contract, or verification burden.

## During

Implement incrementally. Preserve public behavior unless change is required, avoid unrelated edits and unnecessary dependencies, and update documentation when behavior changes.

If implementation reveals an unapproved scope dependency, pause and report it instead of solving it by adding a convenient new feature.

## After

Run relevant checks, review the diff, exercise important edge cases, and record what remains unverified.

Report any scope delta explicitly, including capabilities considered but not implemented and any capability added after confirmation.
