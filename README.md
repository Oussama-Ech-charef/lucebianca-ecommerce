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
├── database/        # schema.sql — snapshot for fresh installs (now 18 tables)
│   └── migrations/  # 001→015 numbered, append-only (see database/migrations/README.md)
└── docs/            # decisions, analysis, runbooks (see docs/README.md)
```

## Stack

- **Frontend:** Next.js (App Router, server-rendered for SEO), Tailwind CSS, TypeScript
- **Backend/API:** PHP 8 OOP — **PDO exclusively** (Prepared Statements, one Singleton connection), Composer/PSR-4 autoloading
- **Database:** MySQL/MariaDB — InnoDB, `utf8mb4` (full Arabic support)
- **Auth:** JWT (`App\Core\Auth` issues/verifies HS256 tokens)

## Requirements

- Node.js 18+ (Next.js 16), npm
- PHP 8.1+ with `ext-pdo_mysql`
- MySQL 8 / MariaDB 10.4+
- Composer (optional — the API ships a manual PSR-4 autoloader fallback)

## Setup

### 1. Database

```bash
# Fresh install (18 tables, includes lookup_token, refresh_tokens, etc.)
mysql -u root -p < database/schema.sql
# Upgrade existing DB (after backup, apply only missing migrations in order)
mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql
# See database/migrations/README.md for the two supported paths and checklist
```

### 2. PHP API

```bash
cd api
cp .env.example .env      # then fill in DB credentials + JWT secret
composer dump-autoload    # optional; the manual autoloader works too
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

## API

- **Public:** `GET /api/health`, `GET /api/csrf-token`, `GET /api/products` (filtered/paginated, `is_active=1`), `GET /api/products/{slug}`, `GET /api/products/new-arrivals`, `GET /api/products/special-offers`, `GET /api/categories`, `GET /api/sizes`, `GET /api/colors`, `POST /api/products/{slug}/reviews` (auth), `GET/POST/DELETE /api/wishlist` (auth), `POST /api/newsletter/*`, `POST /api/cart` (validation), `POST /api/orders` (guest + auth, returns `lookup_token`), `GET /api/orders/{id}?token=` (lookup-token or ownership, 404 to hide existence), `POST /api/payments/initiate`/`callback`/`status/{orderId}` (hash→amount→method→pending→paid idempotent), `POST /api/auth/*`, `GET /api/account*`, `POST /api/contact`, `GET /api/settings/public` (safe public settings)
- **Admin (`Authorization: Bearer <admin JWT>` + `lb_admin_session` gate):** `POST /api/admin/auth/login` (rate-limited), `GET/POST/PUT/DELETE /api/admin/products (+ variants/images)`, `GET/PUT /api/admin/orders`, `GET /api/admin/dashboard-stats`, `GET /api/admin/customers`, `GET/POST/PUT/DELETE /api/admin/categories`, `GET/POST/DELETE /api/admin/sizes|colors`, `GET/PUT/DELETE /api/admin/reviews`, `GET/PUT/DELETE /api/admin/messages`, `GET/PUT /api/admin/settings` (14-key whitelist, no secrets)
- **Auth:** `App\Core\Auth` HS256 JWT (`getSecret()` fail-closed ≥32 chars, header `alg` + `sub>0`/`role`/`iat`/`exp` checks), `AuthMiddleware` role enforcement, `RateLimitMiddleware` on `login`/`register`/`admin_login`/`contact`

See [`api/routes.php`](api/routes.php) (sole route registry) and `docs/decisions/2026-09-24-auth-audit.md`.

## Docs

- `docs/README.md` — index (decisions/analysis/runbooks)
- `docs/decisions/` — 23 dated notes (phase 1 → auth → schema drift `014` → error handling → model naming → order privacy `015` → admin settings → email/notification → frontend prod readiness → auth audit)
- `docs/analysis/2026-09-24-final-audit.md` — final read-only audit (architecture, features, limitations, migrations, security, blockers, verification, checklist)
- `docs/runbooks/` — backup/restore, uptime, https/security-headers

Accurate as of 2026-09-24 (18 tables, migrations `001`→`015`, `frontend/src/lib/config.ts` centralized placeholders).