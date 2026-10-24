# Skill Index

Use this index to select the smallest relevant skill set. Confirm project evidence before loading framework-specific or dependency-specific skills.

| Trigger or task | Primary skill | Supporting skills |
|---|---|---|
| Project structure and stack discovery | `project-discovery` | `requirements-analysis`, `architecture` |
| UI visual direction and references | `design-research` | `product-design`, `web-design-guidelines` |
| Implementing a frontend UI | `frontend` | `product-design`, `accessibility-internationalization` |
| Tailwind layout, components, responsive utilities, or Tailwind configuration | `tailwindcss-development` | `frontend`, `accessibility-internationalization`, `visual-verification` |
| Animation or transitions | `motion-design` | `product-design`, `frontend`, `web-design-guidelines` |
| Scroll-linked interaction | `motion-design` | `frontend`, `mobile`, `web-design-guidelines` |
| React animation | `motion-design` | `frontend`, project-specific React/animation skill if present |
| Vue, Nuxt, or Svelte animation | `motion-design` | `frontend`, project-specific framework skill if present |
| Animation performance or jank | `motion-design` | `quality-assurance`, `frontend` |
| Visual browser verification | `agent-browser` | `web-design-guidelines`, `quality-assurance` |
| Real-browser automation from the terminal | `playwright` | `agent-browser`, `visual-verification`, `quality-assurance` |
| Rendered UI and visual state verification | `visual-verification` | `agent-browser`, `web-design-guidelines`, `quality-assurance`, `accessibility-internationalization` |
| Accessibility and reduced motion | `accessibility-internationalization` | `motion-design`, `web-design-guidelines` |
| Runtime accessibility verification | `accessibility-testing` | `accessibility-internationalization`, `visual-verification`, `quality-assurance`, `playwright` |
| New third-party skill or package | `find-skills` | `security`, `documentation`, `quality-assurance` |
| Create or adapt an Agent skill | `skill-authoring` | `find-skills`, `documentation`, `security`, `quality-assurance` |
| Learn from an explicit correction or repeated Agent mistake | `feedback-loop` | `skill-authoring`, `documentation`, `quality-assurance` |
| Multilingual, RTL, or encoding work | `accessibility-internationalization` | `encoding-and-localization`, `visual-verification`, `web-design-guidelines` |
| Release-readiness or pre-deployment review | `production-readiness` | `quality-assurance`, `security`, `devops`, `documentation`, `visual-verification` |
| AI or RAG feature | `ai-integration` | `security`, `backend`, `database`, `quality-assurance` |
| External API or service integration | `api-integration` | `security`, `backend`, `quality-assurance`, `documentation` |
| API schema and producer/consumer compatibility | `api-contract-testing` | `api-integration`, `backend`, `quality-assurance`, `security` |
| Explicit MCP server or MCP integration work | `mcp-builder` | `api-integration`, `security`, `backend`, `quality-assurance`, `documentation` |
| Backend implementation or review | `backend` | `architecture`, `database`, `security`, `quality-assurance` |
| Laravel project confirmed | `laravel-adapter` | `backend`, `database`, `security`, `quality-assurance`, `tdd` |
| Code review | `code-review` | `quality-assurance`, `architecture`, `security`, `codebase-design` |
| Explicit repository threat modeling or abuse-path analysis | `security-threat-model` | `security`, `architecture`, `backend`, `database`, `quality-assurance` |
| Codebase restructuring or module design | `codebase-design` | `architecture`, `patterns`, `code-review` |
| Database or persistence work | `database` | `backend`, `architecture`, `security`, `quality-assurance` |
| Performance measurement or bottleneck analysis | `performance-audit` | `quality-assurance`, `visual-verification`, `backend`, `database`, `api-integration` |
| Logs, metrics, traces, health checks, or operational visibility | `observability` | `devops`, `production-readiness`, `quality-assurance`, `security` |
| Personal, health, identity, or sensitive-data governance | `privacy-data-governance` | `security`, `security-threat-model`, `database`, `backend`, `documentation` |
| Difficult bug diagnosis | `diagnosing-bugs` | `project-discovery`, `quality-assurance`, `tdd` |
| Plan stress test or adversarial review | `grill-me` | `requirements-analysis`, `architecture`, `security` |
| Hackathon or time-boxed prototype | `hackathon` | `project-management`, `frontend`, `backend`, `quality-assurance` |
| Design or implementation patterns | `patterns` | `architecture`, `codebase-design`, domain skills |
| Scope, milestones, or delivery tracking | `project-management` | `requirements-analysis`, `documentation`, `production-readiness` |
| Test-first implementation | `tdd` | `quality-assurance`, `diagnosing-bugs`, domain skills |

## Routing Rules

- Load only the smallest set that covers the request.
- Do not load or recommend GSAP, a motion library, or a framework-specific skill unless the project uses it, the user requests it, or the requirement justifies it.
- If several skills overlap, prefer the general skill plus the narrowest supporting skill.
- Record why each selected skill was relevant when the task is broad or high risk.
