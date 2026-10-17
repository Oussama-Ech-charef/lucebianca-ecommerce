# Operation Type and Risk Severity

These are two different dimensions and must not be mixed.

## Operation Type

Describe what the action can affect:

- **Read-only:** Inspect, analyze, search, or report findings.
- **Reversible:** Change local files with a clear restoration path.
- **External:** Affect services, deployments, accounts, or other systems.
- **Destructive:** Delete, overwrite, migrate, or permanently change data.

## Risk Severity

Describe the possible impact if the action or defect goes wrong:

- **Low:** Localized, cosmetic, or easy to recover.
- **Medium:** Could affect a feature, user group, integration, performance, or maintainability.
- **High:** Could break a core workflow, corrupt important data, or expose sensitive information.
- **Critical:** Could cause data loss, account compromise, severe security exposure, or a system-wide outage.

Always report both when they are relevant. For example: `Destructive operation / High risk`.

For security findings, add evidence status and conditions. For example: `Potential vulnerability / High severity / external impact if publicly deployed`.

Operation type describes an action or affected capability. It does not describe the likelihood or severity of a security finding.
