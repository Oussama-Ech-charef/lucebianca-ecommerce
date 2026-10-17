# Progressive Delivery

Use this resource when a feature, migration, refactor, or design change spans multiple files, layers, or verification stages.

## Purpose

Keep each increment small, observable, reversible where practical, and consistent with the approved scope. The goal is not to split work artificially; it is to prevent an incomplete or unverified intermediate state from being treated as finished.

## Method

1. Record the current baseline and the confirmed outcome.
2. Separate the work into the smallest meaningful increments, ordered by dependency and risk.
3. Prefer one vertical slice that can be checked end to end before broad structural expansion.
4. After each increment, verify the relevant invariant, preserve existing behavior, and record any new evidence.
5. Recalculate the remaining scope after each increment. Do not implement future behavior merely because the architecture could support it.
6. Finish with a handoff that distinguishes implemented, statically checked, runtime verified, visually verified, prior evidence, and unverified claims.

## Scope gate

Pause for confirmation before an increment that introduces an unapproved:

- user-visible capability;
- dependency, framework, provider, or external service;
- endpoint, data field, schema, or public contract;
- migration or compatibility burden;
- verification surface materially larger than the confirmed request.

Use the existing scope-control rules to classify the change instead of treating every supporting edit as a new feature.

## Completion criteria

An increment is complete only when its intended behavior is implemented, the relevant checks have been run, limitations are recorded, and the next increment is clearly separated from the current result.

## Related guidance

- [`scope-control`](scope-control.md) defines when confirmation is required.
- [`agent-lifecycle`](agent-lifecycle.md) defines the discover, plan, execute, verify, and handoff states.
- [`output-contract`](output-contract.md) defines evidence and verification reporting.
