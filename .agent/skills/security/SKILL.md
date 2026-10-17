---
name: security
description: Assess and implement practical security controls across applications, infrastructure, data, and AI integrations.
---

# Security

Discover the trust boundaries, authentication, authorization, validation, secrets, sensitive data, file handling, dependencies, network exposure, logging, and deployment configuration.

Check for injection, broken access control, insecure direct object references, path traversal, XSS, CSRF, SSRF, unsafe uploads, secret exposure, weak session handling, excessive permissions, insecure defaults, and unsafe error messages when relevant.

Prioritize findings by likelihood and impact. Provide evidence, affected scope, remediation, and verification steps. Do not expose real secrets in reports or examples.

## Finding Classification

For every finding, separate these dimensions:

- **Evidence status:** Confirmed fact, potential vulnerability, assumption, or unverified.
- **Risk severity:** Low, Medium, High, or Critical.
- **Operation type:** Read-only, reversible, external, or destructive only when an actual action or affected capability makes it relevant.
- **Conditions:** Local, intranet, production, authenticated, unauthenticated, browser, host, or deployment conditions required for the impact.

Do not use Operation Type as a substitute for severity. Do not call a vulnerability confirmed merely because code could be dangerous under an unverified deployment condition.

## Static Versus Runtime Evidence

- Code that requests a permission is not proof of the effective operating-system permission.
- A file located under a web root is not proof of public HTTP exposure without the server document root and access rules.
- Missing CSRF protection is a design gap; exploitability depends on the authentication and cookie model.
- Wildcard CORS impact depends on authentication, browser policy, request type, and deployment origin.
- An unpinned dependency is a supply-chain risk indicator, not proof that compromise occurred.

Label runtime, network, OS, browser, and deployment checks as unverified when they were not performed.

## Recommendations

Do not recommend broad exception remapping, blanket permission changes, or security controls without preserving legitimate behavior and explaining deployment assumptions. Prioritize remediation by evidence, affected data, exploitability, and recovery impact.
