---
name: mcp-builder
description: Use only when the user explicitly asks to design, build, review, test, or evaluate a Model Context Protocol server or MCP integration for an external service.
---

# MCP Server Development

Guide the design and delivery of an MCP server that exposes a safe, discoverable, and useful interface to an external service. This skill is optional and must not activate for ordinary API integration, backend work, or application development.

## Before implementation

1. Confirm the target service, intended users, deployment mode, transport, supported language/runtime, authentication model, and in-scope operations.
2. Read the current MCP specification and the selected SDK documentation when implementation depends on version-specific behavior. Do not treat a provider example as a project requirement.
3. Map the service's API, resources, pagination, rate limits, error behavior, sensitive data, and destructive actions.
4. Decide whether tools, resources, prompts, or a combination are needed. Prefer clear workflow-oriented tools when they materially improve discoverability, while preserving useful API coverage.

## Design rules

- Use consistent action-oriented names and concise descriptions that help an LLM choose correctly.
- Define strict input and output schemas with meaningful constraints and examples where useful.
- Support pagination and focused responses; avoid returning unnecessary sensitive or high-volume data.
- Return actionable errors without leaking credentials, internal paths, or private payloads.
- Mark read-only, destructive, idempotent, and open-world behavior accurately when the SDK supports annotations.
- Keep authentication, secrets, retries, timeouts, rate limits, and external side effects explicit.
- Default to least privilege and read-only exploration before enabling write operations.

## Implementation and verification

1. Implement a small core client, shared error handling, response formatting, pagination, and configuration boundary.
2. Add tools incrementally and test each schema, permission boundary, failure mode, and external-call policy.
3. Run the language-appropriate syntax, type, build, and MCP Inspector checks available in the project.
4. Create realistic, independent, stable, read-only evaluations that require tool discovery and multi-step reasoning. Verify answers against known data without using real secrets or destructive operations.
5. Report source checks, runtime checks, external calls, test fixtures, unavailable checks, and remaining assumptions separately.

## Safety boundaries

- Do not create provider accounts, API keys, rooms, webhooks, subscriptions, or external resources without explicit approval.
- Do not install Node.js, Python packages, SDKs, or MCP tooling into a project unless explicitly approved and compatible with its confirmed stack.
- Do not invoke write or destructive tools against real services during testing without explicit authorization and a rollback plan.
- Never expose tokens, cookies, personal data, medical data, or full sensitive responses in logs, screenshots, evaluations, or reports.
- Keep MCP integration code separate from the application runtime unless the user explicitly requests architectural integration.

## Output

Provide the selected transport and runtime, service/API coverage, tool/resource inventory, schemas, auth and side-effect model, threat and failure analysis, evaluation plan/results, files changed, external resources touched, and known limitations.

## Adaptation note

This is a project-agnostic local adaptation of the Apache-2.0-licensed `mcp-builder` skill from `skills-1/skills/mcp-builder/SKILL.md`. Provider-specific paths, copied reference manuals, and automatic dependency installation were intentionally omitted.
