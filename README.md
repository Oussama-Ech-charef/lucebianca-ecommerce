# Luce Bianca — T-Shirt E-commerce Platform

Premium custom t-shirts. Next.js storefront + PHP (OOP) REST API + MySQL.

> The single master spec lives in [`lucebianca-project-full.md`](./lucebianca-project-full.md).
> The docs in [`docs/`](./docs/README.md) record decisions made while building.

## Repo structure

```
lucebianca/
├── frontend/        # React + Next.js storefront (App Router, Tailwind, TypeScript)
├── api/             # PHP REST API (OOP: Core / Controllers / Models / Repositories / Services)
│   ├── public/      # index.php front controller + .htaccess
│   ├── src/         # PSR-4 autoloaded classes  (App\)
│   ├── config/      # database.example.php (real creds go in .env / config/database.php)
│   ├── bootstrap.php
│   └── routes.php   # every route is registered here
├── database/        # schema.sql — run once to create all 14 tables
└── docs/            # analysis + decisions (project documentation convention)
```

## Stack (from the master spec)

- **Frontend:** Next.js (App Router, server-rendered for SEO), Tailwind CSS, TypeScript
- **Backend/API:** PHP 8 OOP — **PDO exclusively** (Prepared Statements, one Singleton connection), Composer/PSR-4 autoloading
- **Database:** MySQL/MariaDB — InnoDB, `utf8mb4` (full Arabic support)
- **Auth:** JWT (this phase's `App\Core\Auth` issues/verifies HS256 tokens)

## Requirements

- Node.js 18+ (Next.js 16), npm
- PHP 8.1+ with `ext-pdo_mysql`
- MySQL 8 / MariaDB 10.4+
- Composer (optional — the API ships a manual PSR-4 autoloader fallback)

## Setup

### 1. Database

```bash
mysql -u root -p < database/schema.sql    # creates lucebianca + 14 tables
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

## Implementing against the API

Only the storefront list/read endpoints are wired in this phase
(`GET /api/products`, `GET /api/products/{slug}`, `GET /api/categories`,
`GET /api/health`). Auth-type endpoints and admin CRUD return `501`
until their roadmap phases land. See [`api/routes.php`](api/routes.php).

## Documentation convention

Any analysis or architecture decision made during development goes under
[`docs/`](./docs/README.md) — short, dated, one topic per file, committed
to git. This keeps the project self-explanatory as it grows.