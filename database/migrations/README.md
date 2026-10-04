# Database migrations

Numbered, append-only migration files. **This folder, not `../schema.sql`,
is the source of truth going forward.**

## Convention

- `001_initial_schema.sql` — the original full schema (all 14 tables). It is
  frozen: never edit it in place.
- Any new table or column change is a new numbered file, e.g.
  `002_add_refresh_tokens_table.sql`, `003_add_<thing>.sql`, ...
- Migrations are applied in numeric order, once each, and recorded in the
  `schema_migrations` tracking table (see `019` + `api/scripts/migrate.php`
  + `api/src/Core/MigrationRunner.php`). Never delete rows; never rewrite
  history — new changes are new files.
- `api/scripts/migrate.php` executes each pending file statement-by-statement
  in AUTOCOMMIT (no wrapping transaction: MySQL/MariaDB implicitly commit
  DDL). A file is recorded only after ALL its statements succeed; on failure
  the runner stops without recording, so re-running retries the same file.
  Files must therefore stay idempotent (INFORMATION_SCHEMA guards).
- `--dry-run` is fully read-only: it never creates `schema_migrations` and
  never executes anything.
- Databases migrated manually before tracking existed are adopted with
  `php api/scripts/migrate.php --baseline` (records without executing —
  only when the schema already contains those effects; optional
  `--baseline=01` prefix filter). The runner refuses to execute from scratch
  on a database that already holds tables but no history (override with
  `--force` after a backup).

`../schema.sql` is the **snapshot for FRESH installs only** (now 19 tables, incl. `015` lookup_token + `016`–`020` columns). See `docs/decisions/2026-09-21-order-payment-privacy.md` for order/payment privacy and `docs/decisions/2026-09-19-schema-drift-audit.md` for earlier drift.

## Two supported paths

1. **Fresh install:** `mysql -u root -p < database/schema.sql` **only**. Do not replay `001`→`020` on an empty DB.
2. **Upgrade existing DB:** after a backup, run `php api/scripts/migrate.php --dry-run` to review, then `php api/scripts/migrate.php` to apply pending files in order (tracked). Example: `mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql`.
3. **Adopt a manually-migrated DB** (has tables, no `schema_migrations` rows): after verifying the schema already contains the effects, run `php api/scripts/migrate.php --baseline` to record history without executing. Never `--baseline` a database that is missing migrations — the missing schema will surface later as obscure runtime errors.

**Why replaying `001`→`020` on an empty DB is NOT supported:** `010_add_newsletter_status_columns.sql:10` does `ALTER TABLE newsletter_subscribers ADD COLUMN is_active` without a guard — after `008` already created the table *with* `is_active`, `010` fails with `Duplicate column` by design (it documents the historical fix; the runner fails loudly there instead of silently skipping, so fresh installs must use the snapshot). `013_add_city_postal_to_orders.sql:14` uses `ADD COLUMN IF NOT EXISTS` (MySQL 8.0.23+ only) which is not portable to MariaDB 10.4 (the project's spec DB) — `014` uses `INFORMATION_SCHEMA`+`PREPARE` for both. The snapshot avoids both issues. `015` backfills `lookup_token` for existing rows and is safe to re-run.

## How to apply (upgrade)

From the project root, in order, only the files your DB has not yet applied:

```bash
mysqldump -u root -p lucebianca > backup_$(date +%F).sql
php api/scripts/migrate.php --dry-run   # review pending (read-only, touches nothing)
php api/scripts/migrate.php             # apply 014→020 once each, tracked
# Adopt a manually-migrated DB instead of executing (see path 3 above):
php api/scripts/migrate.php --baseline
# Legacy manual alternative (same order, one file at a time):
# mysql -u root -p lucebianca < database/migrations/014_schema_drift_repairs.sql
# mysql -u root -p lucebianca < database/migrations/015_add_orders_lookup_token.sql
```

`schema_migrations` records filename + SHA-256 so re-runs skip applied files
and warn on drift. Fresh installs still use `../schema.sql` (now 19 tables +
shipping/reset/newsletter-audit/inventory-release columns).

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
| `016_add_orders_shipping_breakdown.sql` | orders.subtotal_amount + shipping_cost (authoritative totals, backfilled subtotal=total) — INFORMATION_SCHEMA guarded, re-runnable | new — apply after backup |
| `017_add_password_reset_to_users.sql` | users.password_reset_hash (SHA-256) + password_reset_expires_at (1 h, single-use) + index | new — apply after backup |
| `018_add_newsletter_unsubscribe_audit.sql` | newsletter_subscribers.unsubscribe_count audit (tokens are stateless HMAC, no PII stored) | new — apply after backup |
| `019_create_schema_migrations.sql` | schema_migrations tracking table (filename + checksum, append-only) + `api/scripts/migrate.php` runner | new — runner creates if missing |
| `020_add_orders_inventory_released.sql` | orders.inventory_released_at DATETIME NULL (durable idempotent-release marker; additive, re-runnable) | new — apply after backup |