# Migration Workflow

## Related Skills

`database` and `quality-assurance`, with `backend`, `devops`, `documentation`, or `tdd` as required.

1. Define the data or schema change and its consumers.
2. Check compatibility with current and previous application versions.
3. Inspect constraints, indexes, data volume, locks, downtime, and sensitive fields.
4. Design forward migration, rollback or recovery strategy, and backup requirements.
5. Test on representative data without exposing real sensitive information.
6. Apply the smallest safe migration and update dependent code.
7. Verify integrity, performance, application behavior, and rollback readiness.
8. Document deployment order, operational risks, and recovery steps.
