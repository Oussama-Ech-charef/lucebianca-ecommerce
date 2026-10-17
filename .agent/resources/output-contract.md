# Output Contract

## Analysis Report

Include the current state, evidence, architecture, dependencies, risks, missing information, and practical recommendations.

For filesystem or project inventory, also include:

- Resolved workspace, Agent, and project paths.
- Scope and inclusion rules.
- Exact inventory method or command.
- Reconciled file, directory, extension, and area totals.
- Observed facts separated from assumptions.

## Plan Report

Include the goal, scope, acceptance criteria, affected areas, implementation order, verification strategy, assumptions, and risks.

Also include a **Scope Gate** with:

- Explicitly confirmed implementation scope.
- Required behavior changes.
- Required supporting changes.
- Optional enhancements not included by default.
- New capabilities or subsystems detected as dependencies.
- A confirmation request whenever implementing such a dependency would expand scope.

Every plan must clearly separate:

- Confirmed facts and requirements.
- Proposed decisions awaiting approval.
- Temporary assumptions.
- Open questions.
- Recommendations that do not become scope automatically.

Also label evidence provenance as `Current`, `Prior`, `Inferred`, or `Unverified`. Do not present a previous command result as if it ran during the current step.

## Implementation Report

Include the outcome, changed files or modules, important decisions, verification results, limitations, and next action.

Separate the following statuses when relevant:

- Implemented in source.
- Static check passed.
- Runtime verified.
- Prior evidence.
- Unverified.

State whether the result describes the current state or a clean/original baseline. Never describe source-present behavior as operational or fully functional without runtime evidence.

Include a **Scope Delta** section stating whether the implementation stayed within the approved scope. If it did not, identify the added capability, why it was added, and the confirmation that authorized it. If no confirmation exists, the Agent must stop before implementing the expansion.

For every executed check, include the command or method, scope, environment, result, and relevant evidence.

The command must be reproduced exactly as executed; do not use `...` or an illustrative abbreviation. Label evidence as current when rerun in the current step and prior when not rerun. Reference verified Agent file paths and headings, not guessed rule numbers.

Any server, deployment, document-root, route, port, or environment command in next steps must be supported by discovered project configuration or clearly marked conditional.

For security reports, include evidence status, severity, operation type when relevant, deployment conditions, affected assets, and unverified runtime assumptions as separate fields.

Never claim that a check passed when it was not run.
