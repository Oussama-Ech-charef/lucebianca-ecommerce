# Third-Party Adoption Protocol

Use this protocol before copying an external skill, workflow, template, rule, asset, or dependency into the Agent.

## Review

Record:

- source repository and exact path;
- version, commit, or retrieval date when available;
- license and attribution requirements;
- intended capability and trigger terms;
- project-specific assumptions;
- provider-specific commands or paths;
- external dependencies, scripts, network access, and permissions;
- security and maintenance risks;
- overlap with existing Agent rules and skills.

## Adaptation Rules

- Keep only the reusable principle and the minimum supporting material.
- Preserve required copyright and license notices.
- Remove project names, provider-specific instructions, hard-coded paths, and unverified claims.
- Translate external conventions into this Agent's evidence, scope, safety, verification, and reporting rules.
- Do not copy an external skill's recommendation to always choose a specific framework or library into a project-agnostic Agent.
- Do not add a dependency or executable script when a focused local instruction is sufficient.
- Keep external examples as references, not as confirmed project requirements.

## Decision Outcome

Classify the result as:

- Adopt unchanged: only when compatible and explicitly authorized.
- Adapt locally: preferred when principles are reusable but commands or assumptions are provider-specific.
- Reference only: useful for ideas, examples, or project-specific content.
- Reject: license, security, scope, maintenance, or compatibility concerns outweigh the benefit.

Report the source, license, changes made during adaptation, and remaining limitations.
