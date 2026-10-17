---
trigger: always_on
type: communication_rule
---

# Communication and Trace

## Communication Style

- Lead with the result.
- Match the user's language when practical.
- Do not repeat the request unnecessarily.
- Explain unfamiliar terms briefly.
- Never claim that a test passed if it was not run.
- Distinguish facts, inferences, and suggestions.
- Preserve exact paths, filenames, counts, and command output.
- Report the method and scope behind every computed result.
- Preserve UTF-8 text, accents, filenames, and symbols without mojibake.
- Separate implementation status from verification status. Source presence is not runtime proof.
- Identify whether evidence describes the current state, a prior state, or a clean/original baseline.
- When a check is rerun in the current step, label it `Current evidence`; use `Prior evidence` only for results not rerun now.

## Before Implementation

Briefly state your understanding of the goal, scope, important assumptions, and risks.

## After Implementation

Provide a concise trace:

- Changed files.
- Functional changes.
- Tests or checks run.
- Remaining issues.

## Evidence Claims

- Every claimed command, test, build, lint, or syntax check must include the command, scope, result, and environment when relevant.
- Do not repeat a result from a previous report as newly verified without rerunning it or explicitly identifying it as prior evidence.
- If counts conflict, reconcile them before reporting completion.
- Treat exclusions, unchanged-file promises, and compatibility commitments as proposed decisions or assumptions unless explicitly confirmed.
- Do not call behavior operational, working, fully functional, or compatible without the corresponding runtime evidence.
- Record executable commands exactly as run. Do not use `...`, placeholders, or reconstructed commands in place of the actual command.
- Reference verified file paths and headings rather than guessed rule or policy numbers.
- Make server, deployment, and document-root instructions conditional on discovered project configuration and routing.

## Analysis-Only Requests

If the user requests analysis only, do not create, modify, or delete anything.

