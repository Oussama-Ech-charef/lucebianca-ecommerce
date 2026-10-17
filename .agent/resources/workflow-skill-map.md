# Workflow-to-Skill Map

Use the smallest set of skills that covers the workflow. Load additional skills only when the project or task requires them.

| Workflow | Primary skills | Common supporting skills |
|---|---|---|
| Discover | `project-discovery` | `requirements-analysis`, `architecture`, `find-skills` when a capability is missing |
| Plan | `requirements-analysis`, `project-management` | `architecture`, `documentation`, `grill-me` for broad or risky plans |
| Build | `architecture` | `frontend`, `backend`, `database`, `patterns`, `tdd`, `codebase-design` |
| Verify | `quality-assurance` | `security`, `accessibility-internationalization`, `documentation`, `tdd`, `web-design-guidelines`, `agent-browser` |
| Review | `quality-assurance`, `architecture`, `code-review` | `security`, `frontend`, `backend`, `database`, `devops`, `web-design-guidelines`, `codebase-design` |
| Feature Delivery | `requirements-analysis`, `architecture`, `quality-assurance` | Domain-specific skills, `grill-me`, `tdd`, `code-review` |
| Design to Code | `product-design`, `frontend` | `accessibility-internationalization`, `mobile`, `web-design-guidelines`, `agent-browser` |
| Design Research | `design-research`, `product-design` | `frontend`, `web-design-guidelines`, `accessibility-internationalization`, `mobile`, `agent-browser` |
| Motion and Interaction | `motion-design`, `product-design`, `frontend` | `accessibility-internationalization`, `web-design-guidelines`, `mobile`, `agent-browser` |
| AI Feature | `ai-integration` | `security`, `backend`, `database`, `quality-assurance` |
| Ship | `devops`, `quality-assurance`, `production-readiness` | `security`, `database`, `documentation`, `agent-browser` for smoke tests |
| Debug | `project-discovery`, `quality-assurance`, `diagnosing-bugs` | `backend`, `frontend`, `database`, `api-integration`, `agent-browser` |
| Security Audit | `security` | `backend`, `frontend`, `database`, `devops`, `ai-integration`, `code-review` |
| Migration | `database`, `quality-assurance` | `backend`, `devops`, `documentation`, `tdd` |
| Incident Response | `devops`, `quality-assurance`, `diagnosing-bugs` | `security`, `backend`, `database`, `api-integration`, `code-review` |

## Resource Routing

| Need | Read |
|---|---|
| Agent lifecycle, state, or handoff | `resources/agent-lifecycle.md` |
| Exact stack and tool discovery | `resources/stack-detection.md` |
| General decisions and tradeoffs | `resources/decision-matrix.md` |
| Risks and severity | `resources/risk-levels.md`, `resources/operation-and-severity.md` |
| Evidence, inventory, and command trace | `resources/evidence-and-inventory.md` |
| Output structure and verification status | `resources/output-contract.md` |
| Scope expansion | `resources/scope-control.md` |
| External skills or dependencies | `resources/third-party-adoption.md` |
| UI references and motion decisions | `resources/design-reference-protocol.md`, `resources/motion-decision-matrix.md` |
| Encoding, localization, and RTL | `resources/encoding-and-localization.md` |
| Multi-step implementation or staged migration | `resources/progressive-delivery.md` |

## Template Routing

| Deliverable | Template |
|---|---|
| Project discovery report | `templates/project-analysis.md` |
| Feature or implementation plan | `templates/feature-plan.md` |
| Design direction | `templates/design-direction.md` |
| Motion behavior | `templates/motion-specification.md` |
| Decision or tradeoff record | `templates/decision-record.md` |
| Verification or audit report | `templates/verification-report.md` |

## Cross-Cutting Skill Routing

These skills are selected from the request rather than from a dedicated workflow file:

| Request | Skill | Supporting skills |
|---|---|---|
| Create, adapt, or review an Agent skill | `skill-authoring` | `find-skills`, `documentation`, `quality-assurance` |
| Learn from a correction or preserve a demonstrated lesson | `feedback-loop` | `skill-authoring`, `documentation`, `quality-assurance` |
| Verify rendered UI, visual states, or responsive behavior | `visual-verification` | `agent-browser`, `web-design-guidelines`, `quality-assurance` |
| Time-boxed prototype or hackathon delivery | `hackathon` | `project-management`, `frontend`, `backend`, `quality-assurance` |
