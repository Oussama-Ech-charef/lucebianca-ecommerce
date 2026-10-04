# Luce Bianca — T-Shirt E-commerce Platform

Premium custom t-shirts. Next.js storefront + PHP (OOP) REST API + MySQL.

## Repo structure

```
lucebianca/
├── frontend/        # React + Next.js storefront (App Router, Tailwind, TypeScript)
├── api/             # PHP REST API (OOP: Core / Controllers / Models / Repositories / Services)
│   ├── public/      # index.php front controller + .htaccess
│   ├── src/         # PSR-4 autoloaded classes  (App\)
│   ├── config/      # database.example.php (real creds go in .env / config/database.php)
│   ├── bootstrap.php
│   └── routes.php   # every route is registered here (public + admin, see docs)
├── database/        # schema.sql — snapshot for fresh installs (now 19 tables)
│   └── migrations/  # 001→020 numbered, append-only (see database/migrations/README.md)
└── docs/            # decisions, analysis, runbooks (see docs/README.md)
```

## Stack

- **Frontend:** Next.js (App Router, server-rendered for SEO), Tailwind CSS, TypeScript
- **Backend/API:** PHP 8 OOP — **PDO exclusively** (Prepared Statements, one Singleton connection), Composer/PSR-4 autoloading
- **Database:** MySQL/MariaDB — InnoDB, `utf8mb4` (full Arabic support)
- **Auth:** JWT (`App\Core\Auth` issues/verifies HS256 tokens)

## Requirements

- Node.js >=20.9.0 (frontend/package.json `engines`, Next.js 16), npm
- PHP 8.1+ with `ext-mbstring` and `ext-pdo_mysql`
- MySQL 8 / MariaDB 10.4+
- Composer (needed for Cloudinary image uploads; the manual PSR-4 autoloader supports the rest of the API)

## Setup

### 1. Database

```bash
# Fresh install (19 tables, includes lookup_token, refresh_tokens, etc.)
mysql -u root -p < database/schema.sql
# Upgrade existing DB (after backup, apply only missing migrations in order)
mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql
# See database/migrations/README.md for the two supported paths and checklist
```

### 2. PHP API

```bash
cd api
cp .env.example .env      # set DB credentials and generate JWT_SECRET (32+ random characters)
composer install          # install Cloudinary dependency; commit the generated composer.lock
php -S 127.0.0.1:8090 public/index.php
```

Smoke test: `curl http://127.0.0.1:8090/api/health`

### 3. Frontend

```bash
cd frontend
cp .env.example .env.local
npm install
npm run dev               # http://localhost:3000
```

The example environment files contain placeholders only. Keep secrets in the
ignored `.env` / `.env.local` files and never commit those files. Frontend
`NEXT_PUBLIC_*` values are public and are embedded in the frontend build.

## API

- **Public:** `GET /api/health`, `GET /api/csrf-token`, `GET /api/products` (filtered/paginated, `is_active=1`), `GET /api/products/{slug}`, `GET /api/products/new-arrivals`, `GET /api/products/special-offers`, `GET /api/categories`, `GET /api/sizes`, `GET /api/colors`, `POST /api/products/{slug}/reviews` (auth, verified email), `GET/POST/DELETE /api/wishlist` (auth), `POST /api/newsletter/subscribe` + `GET` (read-only link check) / `POST /api/newsletter/unsubscribe` (signed token), `POST /api/cart` (validation), `POST /api/orders` (guest + auth, returns `lookup_token`, totals include server-computed shipping), `GET /api/orders/{id}?token=` (lookup-token or ownership, 404 to hide existence), `POST /api/orders/{id}/cancel` (unpaid only, 409 when paid), `GET /api/payments/config` (no secrets), `POST /api/payments/initiate` (storefront `okUrl`/`failUrl` + API `callbackUrl`, hash covers exact return URLs), `POST /api/payments/callback` (server-to-server: hash→amount→method→pending→paid/failed idempotent, late paid after release rejected), `GET /api/payments/status/{orderId}`, `POST /api/auth/*` (incl. forgot/reset-password), `GET /api/account*`, `POST /api/contact`, `GET /api/settings/public` (safe public settings)
- **Admin (`Authorization: Bearer <admin JWT>` + `lb_admin_session` gate):** `POST /api/admin/auth/login` (rate-limited), `GET /api/admin/auth/me`, `GET/POST/PUT/DELETE /api/admin/products (+ variants/images)`, `GET/PUT /api/admin/orders` (atomic transitions: cancelled+paid rejected, paid never auto-cancelled, 409 conflicts), `GET /api/admin/dashboard-stats`, `GET /api/admin/customers`, `GET/POST/PUT/DELETE /api/admin/categories`, `GET/POST/DELETE /api/admin/sizes|colors`, `GET/PUT/DELETE /api/admin/reviews`, `GET/PUT/DELETE /api/admin/messages`, `GET/PUT /api/admin/settings` (14-key whitelist, no secrets)
- **Auth:** `App\Core\Auth` HS256 JWT (`getSecret()` fail-closed ≥32 chars, header `alg` + `sub>0`/`role`/`iat`/`exp` checks), `AuthMiddleware` role enforcement, `RateLimitMiddleware` on `login`/`register`/`admin_login`/`contact`

See [`api/routes.php`](api/routes.php) (sole route registry) and `docs/decisions/2026-09-24-auth-audit.md`.

## Docs

- `docs/README.md` — index (decisions/analysis/runbooks)
- `docs/decisions/` — 27 dated notes (phase 1 → auth → schema drift `014` → error handling → model naming → order privacy `015` → admin settings → email/notification → frontend prod readiness → auth audit → payments/inventory + security hardening `2026-10-04`)
- `docs/analysis/2026-09-24-final-audit.md` — point-in-time audit 2026-09-24 (architecture, features, limitations, migrations, security, blockers, verification, checklist) plus a dated addendum (§10) for later changes; historical sections are preserved as written
- `docs/runbooks/` — backup/restore, uptime, https/security-headers

Accurate as of the current implementation (19 tables, migrations `001`→`020`, `frontend/src/lib/config.ts` centralized placeholders).
