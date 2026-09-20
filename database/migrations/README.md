# Database migrations

Numbered, append-only migration files. **This folder, not `../schema.sql`,
is the source of truth going forward.**

## Convention

- `001_initial_schema.sql` — the original full schema (all 14 tables). It is
  frozen: never edit it in place.
- Any new table or column change is a new numbered file, e.g.
  `002_add_refresh_tokens_table.sql`, `003_add_<thing>.sql`, ...
- Migrations are applied in numeric order, once each, and recorded nowhere
  today (a real `migrations` tracking table / runner arrives in phase 10
  when the backup & monitoring story lands).

`../schema.sql` is the **snapshot for FRESH installs only** (now 18 tables + `015` lookup_token). See `docs/decisions/2026-09-21-order-payment-privacy.md` for order/payment privacy and `docs/decisions/2026-09-19-schema-drift-audit.md` for earlier drift.

## Two supported paths

1. **Fresh install:** `mysql -u root -p < database/schema.sql` **only**. Do not replay `001`→`015` on an empty DB.
2. **Upgrade existing DB:** after a backup, apply **only not-yet-applied** migrations in numeric order, once each. Example: `mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql`.

**Why replaying `001`→`015` on an empty DB is NOT supported:** `010_add_newsletter_status_columns.sql:10` does `ALTER TABLE newsletter_subscribers ADD COLUMN is_active` without a guard — after `008` already created the table *with* `is_active`, `010` fails with `Duplicate column`. `013_add_city_postal_to_orders.sql:14` uses `ADD COLUMN IF NOT EXISTS` (MySQL 8.0.23+ only) which is not portable to MariaDB 10.4 (the project's spec DB) — `014` uses `INFORMATION_SCHEMA`+`PREPARE` for both. The snapshot avoids both issues. `015` backfills `lookup_token` for existing rows and is safe to re-run.

## How to apply (upgrade)

From the project root, in order, only the files your DB has not yet applied:

```bash
mysqldump -u root -p lucebianca > backup_$(date +%F).sql
mysql -u root -p lucebianca < database/migrations/014_schema_drift_repairs.sql
mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql
```

No `migrations` tracking table exists yet — use the manual checklist below to find which files are already applied.

### Manual checklist — which migrations has my DB applied?

Run these yourself (read-only) on the host that holds the lucebianca DB:

```sql
-- Tables
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
-- Does it have refresh_tokens / admin_refresh_tokens / product_sizes / product_colors?
SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('refresh_tokens','admin_refresh_tokens','product_sizes','product_colors');

-- Users columns (004/005)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION;
-- Expect google_id, email_verified, email_verification_token, email_verification_expires_at

-- Orders columns (009/013/015)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' ORDER BY ORDINAL_POSITION;
-- Expect email VARCHAR(255) NULL, lookup_token VARCHAR(64) NOT NULL UNIQUE, city VARCHAR(100) NOT NULL DEFAULT '', postal_code VARCHAR(20) NOT NULL DEFAULT ''

-- Newsletter lifecycle (008/010)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' ORDER BY ORDINAL_POSITION;

-- Reviews / wishlist indexes
SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' ORDER BY INDEX_NAME;
-- Expect idx_reviews_approved (optional, minor)
SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wishlist' ORDER BY INDEX_NAME;
-- wishlist should have ONLY uq_wishlist_user_product — idx_wishlist_user is redundant

-- Products
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sale_price';
```

Compare output to `001`→`015` definitions. If unknown, treat as **unknown**.

## Current catalog

| File | Purpose | Status |
|---|---|---|
| `001_initial_schema.sql` | Full initial schema (14 tables) | applied — frozen |
| `002_add_refresh_tokens_table.sql` | `refresh_tokens` (SHA-256 hashed, revocable) | applied (phase 2) |
| `003_add_admin_refresh_tokens_table.sql` | `admin_refresh_tokens` (admins are a fully separate table — see file for the FK decision) | applied (phase 4) |
| `004_add_users_google_id.sql` | users.google_id (Google sign-in create-or-link) | applied (phase 13) |
| `005_add_users_email_verification.sql` | users email verification token/expiry + flag | applied (phase 16) |
| `006_add_reviews_table.sql` | reviews (moderated product ratings) — CREATE IF NOT EXISTS, adds no-op if reviews exists; intended idx_reviews_approved is missing on migration-path DBs | applied — superseded by 014 for the missing index |
| `007_add_wishlist_table.sql` | wishlist (per-customer saved products) — CREATE IF NOT EXISTS | applied — superseded: idx_wishlist_user is redundant |
| `008_add_newsletter_table.sql` | newsletter_subscribers — CREATE IF NOT EXISTS with is_active + TIMESTAMP unsubscribed_at | applied — conflicts with 010 on fresh replay |
| `009_add_email_to_orders.sql` | orders.email VARCHAR(255) NULL (guest checkout confirmations) — **truth for orders.email** | applied |
| `010_add_newsletter_status_columns.sql` | newsletter_subscribers.is_active/unsubscribed_at DATETIME — fails with Duplicate column after 008; do not replay on empty DB | applied — history preserved, not idempotent |
| `011_add_sale_price_to_products.sql` | products.sale_price DECIMAL(10,2) NULL | applied |
| `012_add_product_sizes_colors_tables.sql` | product_sizes / product_colors catalog tables | applied |
| `013_add_city_postal_to_orders.sql` | orders.city VARCHAR(100) NOT NULL DEFAULT '' + postal_code VARCHAR(20) NOT NULL DEFAULT '' — uses ADD COLUMN IF NOT EXISTS (MySQL 8.0.23+ only) | applied — not portable to MariaDB, 014 provides portable guard |
| `014_schema_drift_repairs.sql` | Drift repairs for 006/007/008/010/013 portability + snapshot alignment; INFORMATION_SCHEMA+PREPARE, re-runnable, **UNTESTED** (no DB execution) | applied — after backup |
| `015_add_orders_lookup_token.sql` | orders.lookup_token VARCHAR(64) NOT NULL UNIQUE — non-guessable guest lookup token (64 hex, 256-bit), backfilled via UUID, INFORMATION_SCHEMA guarded, **UNTESTED** | new — apply after backup; enables safe guest privacy |