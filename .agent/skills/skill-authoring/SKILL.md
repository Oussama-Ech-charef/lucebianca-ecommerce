---
name: skill-authoring
description: Create, review, or adapt reusable Agent skills with correct routing, scope, metadata, evidence, and validation.
---

# Skill Authoring

Use this skill when creating, updating, reviewing, importing, or organizing a reusable skill for this Agent.

## Before Writing

- Define the capability, intended users, trigger terms, and explicit exclusions.
- Check existing skills for overlap, contradictions, and reusable material.
- Inspect the source, license, version, and dependencies when adapting an external skill.
- Keep project-specific rules, paths, credentials, providers, and assumptions out of universal skills.

## Skill Structure

- Use a lowercase hyphenated folder name that matches the frontmatter `name`.
- Include concise frontmatter with a discriminating `description` that says what the skill does and when it applies.
- Keep the entrypoint focused on decisions, constraints, workflow, and output.
- Put substantial conditional material in a linked reference instead of loading it for every use.
- Add scripts or assets only when they provide a concrete, repeatable benefit.
- Keep automatic discovery enabled unless explicit-only invocation was requested.

## Quality Gate

Review every skill for:

- clear trigger and boundary;
- no duplication of always-on Agent rules;
- no invented requirements or provider assumptions;
- no unsafe permissions or hidden external actions;
- correct references and internal paths;
- UTF-8 content and preserved accents/symbols;
- no unfinished placeholders or empty files;
- practical output and verification expectations.

## External Adaptation

When importing from another repository, follow `resources/third-party-adoption.md`. Preserve required license and attribution, adapt provider-specific behavior, and do not copy a recommendation to always choose a particular technology into this project-agnostic Agent.

## Reporting

Report the skill's purpose, trigger boundary, files changed, source and license when relevant, validation method, overlap review, and known limitations.
