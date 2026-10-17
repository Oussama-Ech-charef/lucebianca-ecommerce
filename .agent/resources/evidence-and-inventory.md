# Evidence and Inventory Protocol

Use this protocol whenever the Agent reports project structure, file counts, executed commands, tests, builds, or verification results.

## Resolve Scope First

1. Identify the current workspace root.
2. Resolve the exact path of the Agent configuration.
3. Resolve the exact path of the project being analyzed.
4. Confirm whether the Agent configuration and project are siblings, nested, or the same directory.
5. Report the resolved paths before analyzing.

Never replace a confirmed path with a guessed name such as `.agent` or `.agent_oussama_ech`.

## Inventory Rules

- Use the filesystem or repository tools for counts; never count manually from a tree pasted in chat.
- State whether hidden files, ignored files, generated files, and symlinks are included.
- Count files and directories with one defined scope and one reproducible method.
- Cross-check totals: extension totals must equal the total file count, and area totals must reconcile with the root total.
- Distinguish directories inside a root from the root directory itself.
- List the exact command or tool method used for every computed inventory.

## Verification Evidence

- A claim that a command, test, build, lint, or syntax check ran must include the command, scope, environment, result, and relevant output.
- Do not inherit execution claims from an earlier report without rerunning or explicitly citing the earlier evidence.
- If a check was executed during the current step, classify it as `Current`; classify it as `Prior` only when it was not rerun. Never write contradictory labels such as “Prior — re-executed above”.
- Preserve the exact executable command. Do not replace part of it with `...`, a placeholder, or an illustrative shorthand.
- Cite a verified file path and heading for Agent rules. Do not cite a rule number unless that number was verified in the current Agent configuration.
- Treat server commands, document roots, routes, ports, and deployment steps as environment-dependent. Inspect configuration first and mark any unverified command as conditional.
- Classify implementation and verification separately: `Implemented in source`, `Static check passed`, `Runtime verified`, `Prior evidence`, or `Unverified`.
- Do not call source-present behavior operational or working without a relevant runtime check.
- State whether the inspected project is a clean/original baseline or already contains prior changes. A test of the current state does not prove behavior against the original baseline.
- Separate observed facts, inferences, assumptions, recommendations, and untested areas.
- Treat claims about exclusions, unchanged files, unchanged schemas, or guaranteed compatibility as decisions or assumptions unless directly confirmed.
- If the environment cannot run a check, report it as unavailable rather than successful.

## Reporting Quality

- Prefer UTF-8 output and preserve filenames exactly.
- Report encoding or display problems separately from project facts.
- If text appears as mojibake, correct the display or mark the text as an encoding issue instead of treating it as project content.
- If two counts disagree, stop and reconcile them before presenting a final report.
