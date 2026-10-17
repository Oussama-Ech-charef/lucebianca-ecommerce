# Scope Control

## Purpose

Prevent an implementation from silently growing beyond the user's confirmed request.

## Change Classification

### Required Behavior

Behavior explicitly requested by the user or established by current project evidence.

### Required Supporting Change

An internal change that is necessary to deliver confirmed behavior and does not add an unrelated user-visible capability.

### Optional Enhancement

A useful improvement that is not necessary for the confirmed behavior. It must be proposed separately and must not be implemented by default.

### Scope Dependency

A missing mechanism that the requested behavior appears to depend on. The Agent must explain the dependency and request confirmation before adding it when it introduces a new capability, subsystem, dependency, data contract, or materially larger verification surface.

## Decision Test

Before editing, ask:

1. Is this change explicitly requested or verified as necessary?
2. Does the project already contain the mechanism, or would this create one?
3. Would a user notice a new capability, control, endpoint, field, integration, metric, or workflow?
4. Does it expand architecture, dependencies, data contracts, or test obligations?

If the answer to question 3 or 4 is yes and the capability was not approved, stop and request confirmation.

## Example

“Show an empty state when filters produce no results” does not automatically authorize adding filtering. If no filter mechanism exists, the Agent must either:

- ask for approval to add filtering;
- implement only an already-existing filter path; or
- ask whether the requirement should be narrowed to the initial empty state.

The Agent must not add a filter bar merely because it makes the requested state possible.
