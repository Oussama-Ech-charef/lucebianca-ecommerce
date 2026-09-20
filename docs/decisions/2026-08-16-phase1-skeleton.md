# Phase 1 — Project skeleton + database init

Date: 2026-08-16

## What was done

1. **Repo layout** — split into `frontend/` (Next.js) and `api/` (PHP), with
   `database/`, `docs/` and a root `.gitignore` (from spec section 12).
   Next.js 16.3.1 (App Router + Tailwind v4 + TypeScript) scaffolded with
   `create-next-app`.
2. **PHP OOP skeleton** matching spec section 3.3:
   - `Core/` — `Database` (PDO Singleton), `Router`, `Request`, `Response`,
     `Auth` (hand-rolled HS256 JWT), `AuthMiddleware`, `RequestContext`,
     `Env`, `Middleware` interface.
   - `Controllers/` — public + `Admin/` controllers; list endpoints are live,
     auth/admin endpoints are `501` stubs for their phases.
   - `Models/` — `Product`, `Category`, `User`, `Order`, `Variant` (anemic
     data holders).
   - `Repositories/` — PDO + Prepared Statements/`bindValue` only
     (`ProductRepository`, `CategoryRepository`, `UserRepository`).
   - `Services/` — `ProductService`, `AuthService` (business-logic boundary).
   - Front controller `public/index.php` + Apache `.htaccess`.
3. **Database** — `database/schema.sql` (verbatim from spec section 4) applied
   against the local MariaDB 10.4 (`lucebianca` DB now has all 14 tables).

## Decisions

- **Manual PSR-4 autoload fallback** — `composer` is not installed on the dev
  machine, so `api/src/autoload.php` replicates the composer.json PSR-4
  mapping (`App\` → `src/`) and is skipped once `vendor/autoload.php` exists.
- **JWT is dependency-free for now** — `Auth` implements RFC 7519 HS256
  directly. Keeps the skeleton running with zero vendor deps; a battle-tested
  library (e.g. `firebase/php-jwt`) can replace it later if desired.
- **Requests read headers from `$_SERVER`**, not `getallheaders()`, so the
  API also works under PHP's built-in dev server (used by the README).
- **MariaDB is the local "MySQL"** — the machine runs XAMPP MariaDB 10.4 on
  port 3306 (root, empty password) plus a MySQL 8.0 service. The schema is
  compatible with both.

## Next steps

- Phase 2: numbered migration files for schema changes.
- Phase 3: real auth (register/login/Google) + admin JWT guarding.