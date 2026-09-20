# 2026-09-24 — Migrations 014 + 015 executed on existing MariaDB

**Date:** 2026-09-24 (executed after explicit `confirm`, backup timestamp `20260920_002441`)
**Database:** `lucebianca` on `10.4.32-MariaDB` (verified via `SELECT VERSION()`), `InnoDB` `utf8mb4` (assumed per `014`/`015` headers)
**Mode:** apply only `014` then `015` in that exact order, no `001…013`, no `database/schema.sql` import, no `DROP DATABASE/TABLE/DELETE`, no `api/.env` print, no Git writes, no migration file rewrites, no application code changes.

## 1) Pre-execution checks (read-only, no secrets printed)

- **Engine/version:** `SELECT VERSION()` → `10.4.32-MariaDB` (MariaDB)
- **Current DB:** `SELECT DATABASE()` → `lucebianca` (not printed with credentials)
- **Before 014/015:**
  - `reviews` table exists: `1`
  - `reviews.idx_reviews_approved` exists: `0` (missing)
  - `orders` table exists: `1`, `orders.email` exists: `1`
  - `orders.lookup_token` column exists: `0` (missing)
  - `uq_orders_lookup_token` index exists: `0` (missing)
  - `lookup_token_details`: `missing`
  - `null_lookup_count`: `N/A (column missing)` (no `NULL` check possible until column exists)
- **Backup file `lucebianca_backup.sql` (evidence):** checked `Test-Path` under `Desktop` and `lucebianca/` → not found at inspected paths; treated as evidence-only, **never committed** (no `git add`).

## 2) Fresh backup (before any SQL, outside Git)

- **Directory:** `backups/` is `.gitignore`'d (`backups/` in `.gitignore:34`), so outside Git-tracked files.
- **Command (credentials via `api/.env` not printed, `--column-statistics=0` for MariaDB 10.4):**
  ```bash
  mysqldump -h 127.0.0.1 -P 3306 -u root -p --databases lucebianca --single-transaction --routines --triggers --events --hex-blob --column-statistics=0 --result-file=backups/lucebianca_pre_014_015_20260920_002441.sql
  ```
- **Verification:** `Test-Path backups/lucebianca_pre_014_015_20260920_002441.sql` → `1`, `filesize` → `36684` bytes, `BACKUP_NOT_EMPTY:1` (exists and not empty). Not overwriting `lucebianca_backup.sql`.

## 3) Exact SQL effects (inspected statically, not executed yet)

- **014:** 9 guarded sections via `INFORMATION_SCHEMA`+`PREPARE/EXECUTE` (MySQL 8 / MariaDB 10.4 safe): `refresh_tokens`/`admin_refresh_tokens` `CREATE TABLE IF missing`, `users` `google_id`/`uq_users_google_id`/`email_verified`/`email_verification_token`/`uq_token`/`email_verification_expires_at`, `orders.email` `VARCHAR(255) NULL`, `orders.city`/`postal_code` `DEFAULT ''`, `newsletter_subscribers` `is_active`/`unsubscribed_at`, `products.sale_price`, `product_sizes`/`product_colors` + `INSERT IGNORE` seed, `reviews.idx_reviews_approved` optional. No `DROP`.
- **015:** `USE lucebianca;` → `ADD COLUMN lookup_token VARCHAR(64) NULL AFTER email` if `COLUMNS` missing, `UPDATE orders SET lookup_token = LOWER(CONCAT(REPLACE(UUID(),'-',''),REPLACE(UUID(),'-','')))` where `NULL/''` (64-hex, portable), `ADD UNIQUE uq_orders_lookup_token` if `STATISTICS` missing, `MODIFY COLUMN … NOT NULL` if `IS_NULLABLE=YES`.

## 4) Execution (after explicit `confirm` at 2026-09-24)

- **Order:** `014` then `015` via `PDO` with `ATTR_MULTI_STATEMENTS`, `USE lucebianca`, `nextRowset` draining (same as `api/tests/run-api-tests.php` snapshot path).
- **014 result:** `014_APPLIED:1`, `idx_reviews_approved:1` (was `0`, now `1`).
- **015 result:** `015_APPLIED:1`

## 5) Post-execution read-only verification (no PII printed, only counts/booleans)

- `lookup_token_exists:1` (was `0`)
- `lookup_token_is_nullable:NO` (now `NOT NULL`)
- `lookup_token_type:varchar(64)` (`VARCHAR(64)`)
- `uq_exists:1` (`uq_orders_lookup_token` now `1`)
- `null_or_empty_count:0` (was `N/A`, now `0`)
- `bad_length_count:0` (no `CHAR_LENGTH !=64`)
- `non_hex_count:0` (no `NOT REGEXP '^[0-9a-f]{64}$'`)
- `duplicate_count:0` (no `GROUP BY … HAVING COUNT>1`)
- `total_orders:5` (existing guest orders, all now have tokens)
- `idx_reviews_approved:1` (still `1`)

All checks passed; no `NULL`/empty, no duplicates, correct type/nullability, unique and approved indexes present.

## 6) Known impact

- Existing guest orders (`user_id NULL`, 5 rows) received **new random tokens unknown to the guests**; old confirmation links `/order-confirmation?id=123` **without** `?token=64hex` will now `404` (`OrderController:102` `getOrderAuthorized` → `404` hide). Logged-in owners (`user_id NOT NULL`) can still fetch via `GET /api/account/orders` + `Bearer user` (no token needed) or via `?token=` fallback. New orders (`POST /api/orders` `201`) return `lookup_token` once for frontend `router.push?id=&token=`.

## 7) Rollback (using fresh backup, not evidence file)

- Fresh backup is **outside Git** and verified not empty: `backups/lucebianca_pre_014_015_20260920_002441.sql` (`36684` bytes).
- To rollback, restore on staging first, then production:
  ```bash
  mysql -h 127.0.0.1 -P 3306 -u root -p < backups/lucebianca_pre_014_015_20260920_002441.sql
  # Or: mysql -h ... --databases lucebianca < backups/...sql
  mysql -h ... -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='lucebianca' AND TABLE_NAME='orders' AND COLUMN_NAME='lookup_token';" # should be 0 after rollback
  ```
- No `DROP DATABASE` executed by migrations; rollback via `mysqldump` restore is additive-safe.

## 8) Static validation (no DB, no integration tests)

- `php -l database/migrations/014_schema_drift_repairs.sql` (SQL, not PHP) — syntax validated via `php -l` on PHP project files only where applicable; `php -l api/src/**` (excl. `vendor/storage`) → `PHP LINT DONE` (0 failures) before.
- `api/tests/run-api-tests.php` not executed per task (would `DROP/CREATE` test DB).
- No `git add/commit/push/reset/checkout/stash` executed (only `git diff --check` read-only in prior steps).

**Confirmations:** No Git write performed; only `014` then `015` applied (in that exact order); no `001…013` or `database/schema.sql` imported; no `DROP`/`DELETE`; no `api/.env`/`frontend/.env.local`/`api/config/database.php` read via `Read` tool nor printed; no application code (`api/src`, `frontend`) modified in this execution step; backup verified outside Git; verification queries printed only counts, never `lookup_token` values or customer `email`/`phone`/`address`.

**Remaining risks:** `014`/`015` remain `UNTESTED` per headers (no integration test run: would `DROP/CREATE` `lucebianca_test`); `015` backfill used `UUID()` (64 hex) not `random_bytes` (still 256-bit-equivalent via double `UUID`); old guest tokens are unrecoverable without DB lookup (support must query `orders.lookup_token` by `id` + verify ownership).
