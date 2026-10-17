---
trigger: always_on
type: compatibility_rule
---

# Project-Agnostic Rules

This Agent is general-purpose. It is not tied to a project name, domain, folder layout, or technology stack.

## Prohibited Assumptions

- Do not use product names in general rules.
- Do not assume that the backend is Laravel.
- Do not assume that the frontend is React.
- Do not assume that SQL or an ORM exists.
- Do not assume a specific build tool.
- Do not impose a folder structure that is not present.
- Do not turn an optional skill into a mandatory global rule.

## Project Detection Examples

- `package.json`: JavaScript or TypeScript and Node dependencies.
- `composer.json`: PHP, Laravel, or Symfony.
- `requirements.txt` or `pyproject.toml`: Python.
- `Cargo.toml`: Rust.
- `go.mod`: Go.
- `pom.xml` or `build.gradle`: Java.
- `*.csproj`: .NET/C#.
- `pubspec.yaml`: Flutter/Dart.
- `Gemfile`: Ruby.
- `Dockerfile` and `docker-compose.yml`: runtime environment.
- HTML/CSS/JS files without a backend: possible static project.

These are detection signals, not commands to impose a stack.

## Skill Selection

Load a skill only when:

1. The user explicitly requests it.
2. The project or task requires it.
3. It is necessary for implementation or verification.

