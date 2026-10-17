# Security Audit Workflow

## Related Skills

`security`, with `backend`, `frontend`, `database`, `devops`, `ai-integration`, or `code-review` as required.

1. Define the audit scope, assets, users, trust boundaries, and authorization assumptions.
2. Discover authentication, authorization, input validation, secrets, sensitive data, files, dependencies, integrations, and deployment exposure.
3. Review common risks relevant to the stack and feature.
4. Verify findings with safe, non-destructive checks.
5. Classify each finding by evidence, likelihood, impact, and severity.
6. Separate evidence status, risk severity, operation type, and deployment conditions.
7. Distinguish static code evidence from runtime, network, browser, OS, and deployment evidence.
8. Provide remediation, compensating controls, and verification steps.
9. Do not include real secrets or unsafe exploit instructions in the report.
10. Record limitations and areas that were not assessed.

## Finding Format

For each finding, report:

- ID and title.
- Evidence status: Confirmed, Potential, Assumption, or Unverified.
- Severity: Low, Medium, High, or Critical.
- Operation type only when relevant.
- Exact location and evidence.
- Conditions required for impact.
- Affected assets and users.
- Recommendation and safer alternative.
- Verification method.

Do not label a conditional deployment risk as an unconditional confirmed exploit. Do not label source-code configuration as effective OS or server behavior without checking it.
