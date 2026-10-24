---
name: laravel-adapter
description: Apply Laravel-aware discovery, implementation, testing, and review guidance only when the project is confirmed to use Laravel. Preserve the project's existing conventions and do not introduce Laravel into a non-Laravel project.
---

# Laravel Adapter

This is an optional framework adapter for Laravel projects. Activate it only when project evidence confirms Laravel, such as `laravel/framework` in `composer.json` or `composer.lock`, a working `artisan` entry point, or the documented Laravel application structure. A filename, a PHP dependency alone, or a user interface resemblance is not sufficient evidence.

## Before Using Laravel Guidance

1. Read the installed Laravel version from `composer.lock` or the resolved dependency metadata.
2. Inspect the project's routes, controllers, models, migrations, requests, policies, jobs, views, tests, and project-owned rules.
3. Identify the conventions the project actually uses before choosing between valid Laravel approaches.
4. Treat framework defaults and formatter- or refactoring-tool-owned transformations as defaults, not project conventions.

## Implementation Guidance

- Keep controllers focused on transport concerns and extract business operations only when the project has a real boundary that benefits from it.
- Use Eloquent relationships, scopes, casts, and model-aware queries when they match the existing project style; avoid repositories or extra layers without a demonstrated need.
- Validate untrusted input, authorize protected actions, use only intended validated fields, and preserve CSRF protection for state-changing browser forms.
- Use transactions, database constraints, atomic updates, or explicit row/cache locks for invariants that can race. Validation alone does not prevent concurrent writes.
- Add indexes for measured query patterns, prevent N+1 queries, and keep queries out of Blade templates.
- Give outbound HTTP calls explicit timeouts, retry only safe or idempotent operations, and handle non-success responses deliberately.
- Keep secrets in configuration or the deployment secret store, never in committed source or reports.
- For tests, follow the project's PHPUnit/Pest conventions, test observable behavior, isolate mutable data and nondeterminism, and verify response, persistence, and side effects for writes.

## Boundaries

- Do not install Composer packages, change the Laravel version, run migrations, seed data, deploy, or modify production resources without explicit authorization.
- Do not apply Laravel guidance to a PHP project merely because it uses PHP, MySQL, Blade-like templates, or Tailwind CSS.
- Do not include Laravel Cloud deployment behavior here. Cloud deployment requires a separate, explicit provider-specific workflow.
- Report source evidence, static checks, runtime checks, browser checks, and deployment checks separately.

## Routing

Combine this adapter only with the smallest relevant universal skills, such as `backend`, `database`, `security`, `quality-assurance`, `tdd`, `api-integration`, or `tailwindcss-development` when Tailwind is also confirmed. Prefer existing project conventions over this adapter whenever they conflict.
