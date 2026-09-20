# 2026-09-19 — Schema drift audit (read-only) + approved snapshot & 014

**Date:** 2026-09-19  
**Mode:** read-only audit (Phase 1) + approved snapshot/migration (Phase 2) — no git writes, no `.env`, no SQL execution, no installs, no `api/src`/`frontend` edits, no existing migration edits.  
**Assumed DB:** MySQL 8.0.x or MariaDB 10.4+ (InnoDB `utf8mb4_unicode_ci`). `014` is **UNTESTED** (no DB connection per hard rule).  
**Production DB state:** **unknown** — not inspected; evidence is file-only.

## 1) Final source of truth

- **Migrations folder is authoritative for upgrades:** `database/migrations/` (`001`→`014`) is the ordered, append-only history. Never edit `001`…`014` in place.
- **Snapshot is authoritative for fresh installs:** `database/schema.sql` (now 301 lines, 18 tables) is the full snapshot for `CREATE DATABASE` on an empty host. It was aligned in this step to equal the logical result of `001`→`013` plus drift repairs, with approved exceptions (see §3).
- **Two supported paths (approved):**
  1. **Fresh install** — `mysql -u root -p < database/schema.sql` **only**. Do not replay `001`→`013` on an empty DB.
  2. **Upgrade existing DB** — after `mysqldump` backup, apply **only not-yet-applied** migrations in numeric order (typically just `014` if `001`→`013` already ran). See checklist in §5 and in `database/migrations/README.md`.

**Why replaying `001`→`013` on empty DB is NOT supported:**

- `010_add_newsletter_status_columns.sql:10` does `ALTER TABLE newsletter_subscribers ADD COLUMN is_active …` without a guard. After `008_add_newsletter_table.sql:5` already created the table with `is_active`, `010` fails with `Duplicate column 'is_active'` (`ERROR 1060`). Evidence: `008:7-8` defines `is_active`+`unsubscribed_at`; `010:11` re-adds `is_active`.
- `013_add_city_postal_to_orders.sql:14` uses `ADD COLUMN IF NOT EXISTS` — syntax exists only on MySQL 8.0.23+. On MariaDB 10.4 (project spec) it parses as error. `014` replaces it with `INFORMATION_SCHEMA`+`PREPARE/EXECUTE` (portable, re-runnable). Evidence: `013:14` `IF NOT EXISTS`; `schema.sql:128-129` now carries `DEFAULT ''` to match `013` intent.

## 2) Migration order

`001_initial_schema.sql` (14 tables, frozen) → `002` refresh_tokens → `003` admin_refresh_tokens → `004` users.google_id → `005` users email verification (3 cols + unique) → `006` reviews (IF NOT EXISTS) → `007` wishlist (IF NOT EXISTS) → `008` newsletter (IF NOT EXISTS, with is_active) → `009` orders.email `VARCHAR(255) NULL` → `010` newsletter lifecycle duplicate add → `011` products.sale_price → `012` product_sizes/product_colors + seed → `013` orders.city/postal `DEFAULT ''` (IF NOT EXISTS) → `014_schema_drift_repairs.sql` (INFORMATION_SCHEMA guards, re-runnable, UNTESTED).

`README.md` catalog now lists `013`+`014` and notes `006`/`007`/`008` superseded.

## 3) Known drift — evidence from both sides, which is wrong, severity

For each: `schema.sql:line` vs `migrations:line` vs code ground truth (`api/src/...:line`). Production remains **unknown**.

### D1 — `refresh_tokens` missing in snapshot — CRITICAL
- `schema.sql` **before:** no table (grep `refresh_tokens` empty); **after:** `schema.sql:269-284` `CREATE TABLE refresh_tokens` added.
- `002_add_refresh_tokens_table.sql:15` defines it.
- `RefreshTokenRepository.php:19`/`AuthService.php:45` queries `refresh_tokens`.
- **Wrong:** `schema.sql` (snapshot stale). Fixed in this step.

### D2 — `admin_refresh_tokens` missing — CRITICAL
- `schema.sql` **before:** no table; **after:** `schema.sql:286-301`.
- `003_add_admin_refresh_tokens_table.sql:22`.
- `AdminRefreshTokenRepository.php:15`.
- **Wrong:** `schema.sql`.

### D3-D6 — `users` Google/verification columns missing — CRITICAL
- `schema.sql:15` **before:** `users` had `id,name,email,password_hash,phone,created_at` only.
- `004:20` `google_id VARCHAR(255) NULL` + `uq_users_google_id`; `005:28-32` `email_verified TINYINT(1) NOT NULL DEFAULT 0`, `email_verification_token VARCHAR(255) NULL` + unique, `email_verification_expires_at DATETIME NULL`.
- Code: `User.php:20` `googleId,emailVerified,token,expiresAt`; `UserRepository.php:49,64,95` queries those columns.
- **After:** `schema.sql:18-32` now lists all six columns + uniques.
- **Wrong:** `schema.sql` before.

### D7 — `orders.email` — CRITICAL (approved: keep `009` truth)
- `schema.sql:123` **before:** `email VARCHAR(150) NOT NULL`; **after:** `email VARCHAR(255) NULL` (`schema.sql:132`).
- `009:6` `ADD COLUMN email VARCHAR(255) NULL AFTER phone`.
- Code: `Order.php:29` `?string $email` (nullable-tolerant) but `OrderService.php:143` validates `email required` — app requires email, DB allows NULL for backward compat.
- **Wrong:** `schema.sql` before (wrong length+nullability). Approved truth is `009` `VARCHAR(255) NULL` (no narrowing). **No `MODIFY` in `014`** per approval.

### D8-D9 — `orders.city`/`postal_code` defaults — MAJOR
- `schema.sql:119-120` **before:** `city VARCHAR(100) NOT NULL` (no default), `postal_code VARCHAR(20) NOT NULL`.
- `013:14-15` `ADD COLUMN IF NOT EXISTS city VARCHAR(100) NOT NULL DEFAULT ''` / `postal_code … DEFAULT ''`.
- **After:** `schema.sql:128-129` now `DEFAULT ''`.
- **Wrong:** `schema.sql` before (missing defaults). Migration intent is to give existing rows `''`; snapshot should match.

### D10 — `reviews.idx_reviews_approved` — MINOR/optional
- `schema.sql:193` **before:** only `KEY idx_reviews_product`; `001:189` same.
- `006:14` `KEY idx_reviews_approved (is_approved)`.
- Code: `ReviewRepository.php:41,70` filters `is_approved=1` — index helps but not required.
- **After:** `schema.sql:203` now has `KEY idx_reviews_approved`; `014:40-43` adds it conditionally as optional.
- **Wrong:** history — `006` used `CREATE TABLE IF NOT EXISTS reviews` so on a DB that already had `reviews` from `001`, the `ADD KEY` never happened. `014` repairs it; snapshot now includes it.

### D12 — `wishlist` indexes — MINOR (dropped per approval)
- `schema.sql:209` `UNIQUE KEY uq_wishlist_user_product (user_id,product_id)` only.
- `007:10-11` `UNIQUE KEY idx_wishlist_user_product` + `KEY idx_wishlist_user (user_id)`.
- Code: no dependency on second index; composite unique already covers `user_id` prefix.
- **Decision per approval:** `idx_wishlist_user` is redundant — **not added** in snapshot or `014`. **Wrong:** `007` second key is redundant.

### D13-D15 — `newsletter_subscribers` — MAJOR
- `schema.sql:219-226` **before:** `email 150`, `is_active`, `unsubscribed_at DATETIME`, `uq_newsletter_email`.
- `001:215` `email 150`, no lifecycle; `008:7` `email 255`, `is_active`, `unsubscribed_at TIMESTAMP`, `idx_newsletter_email`; `010:11` `ADD COLUMN is_active …`, `ADD COLUMN unsubscribed_at DATETIME`.
- Divergence: email `150` vs `255`; `unsubscribed_at` `TIMESTAMP` (`008`) vs `DATETIME` (`010`/`schema`); key name `uq_` vs `idx_` (`001`+`schema` vs `008`).
- **History bug:** `010:11` duplicates `008:7` → replay fails `Duplicate column`; `008` commented `IF NOT EXISTS` but `010` not guarded.
- **Per approval:** No `TIMESTAMP`→`DATETIME` conversion in `014`; snapshot keeps `DATETIME`+`150`+`uq_` (as before), `014` only ensures columns **exist** (`is_active`, `unsubscribed_at`) without type change. **Wrong:** history (conflicting definitions) — snapshot documents one converged choice (DATETIME).

### D16-D17 — `products.sale_price`, `product_sizes`/`product_colors` — consistent
- `schema.sql:59` `sale_price`; `011:5` same — no drift.
- `schema.sql:242-256` present; `012:7,14` same — no drift.

### D18 — `006/007/008` redundant `CREATE IF NOT EXISTS` — MAJOR
- These files re-create tables already in `001` with divergent indexes/types but act as no-ops when replayed after `001`. Hence D10/D12/D15.
- **Wrong:** history design — should have been `ALTER TABLE` patches; `014` now provides portable patches.

### D19 — `migrations/README.md` catalog — MINOR/docs
- **Before:** `README.md:30-43` listed up to `012`, said `schema.sql equals 001 today` (`README.md:16`).
- **After:** now lists `013` and `014`, corrects snapshot note, explains two paths and checklist, notes `006/007/008/010/013` portability.
- **Wrong:** `README.md` before.

### D20 — `013 IF NOT EXISTS` portability — MAJOR
- `013:14` uses MySQL 8.0.23+ syntax; project spec is MariaDB 10.4 + MySQL 8.
- **Wrong:** `013` (frozen, kept) — `014` provides portable `INFORMATION_SCHEMA` alternative.

All other tables (`coupons`, `contact_messages`, `settings`, `categories`, `product_variants` with `uq_variant_combo`, `product_images`, `order_items` with `RESTRICT`, `products`) show **no drift**.

## 4) What was changed in this step (files)

- `database/schema.sql:1` header + `18-32` users + `128-132` orders + `203` reviews + `269-301` refresh tables (18 tables total). Snapshot for fresh installs; do not replay `001`→`013`.
- `database/migrations/014_schema_drift_repairs.sql` (new, 124 lines) — INFORMATION_SCHEMA+PREPARE, re-runnable, UNTESTED.
- `database/migrations/README.md` — catalog now `001`→`014`, two-path guidance, checklist, portability notes.
- This doc `docs/decisions/2026-09-19-schema-drift-audit.md` — audit record, deployment.

No `api/src`, `frontend`, or `001`…`013` edits. No `.env` reads, no SQL execution.

## 5) Safe deployment (upgrade existing DB)

1. **Backup:** `mysqldump -u root -p lucebianca > backup_$(date +%F_%H%M).sql` and copy off-host; verify `gzip -t` and `mysqldump` return code.
2. **Check which migrations are already applied** (read-only, run yourself):

```sql
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' ORDER BY ORDINAL_POSITION;
SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' ORDER BY ORDINAL_POSITION;
SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('reviews','wishlist') ORDER BY TABLE_NAME, INDEX_NAME;
```

3. **Apply only missing files in order.** If `001`→`013` already ran, apply **only** `014`:

```bash
mysql -u root -p lucebianca < database/migrations/014_schema_drift_repairs.sql
```

Do **not** run `mysql < database/schema.sql` after migrations — it would `CREATE DATABASE` and duplicate tables.

4. **Validate (static):** `php -l database/migrations/014_schema_drift_repairs.sql` is not PHP, but `php -l api/src/Repositories/*.php` still passes; compare column lists with `Select-String -Path database/schema.sql -Pattern 'CREATE TABLE'` vs replayed migrations (read-only script).
5. **Rollback:** `014` performs only additive `CREATE`/`ADD COLUMN`/`ADD KEY` (no drops). To revert, restore the `mysqldump` file on a staging host first, run `mysql lucebianca < backup.sql`, re-validate. Do not `DROP` lifecycle columns — they hold data.

## 6) Validation — static only (run, not modifying DB)

- `php -l` on touched PHP: `database/schema.sql` and `README.md` are SQL/md, so lints run on `api/src/Repositories/UserRepository.php`/`OrderRepository.php`/`ReviewRepository.php` remain `No syntax errors` (unchanged code, ground truth).
- Read-only list comparison: `Select-String -Pattern CREATE TABLE` on `schema.sql` (18) vs `001` (14) vs disk `002`/`003`/`012` counts match expected; `Select-String -Pattern ALTER TABLE` shows `009`→`014` mapped.
- `014` syntax checked by file read only — noted UNTESTED per hard rule.

## 7) Remaining risks

- **Production state remains unknown** until checklist queries run on the real host.
- `014` is **UNTESTED** (no DB) — run first on a staging clone of the production dump; watch for `IF(@var)` parsing on older MariaDB.
- `newsletter_subscribers.email` length divergence (`150` snapshot vs `255` `008`) not converged — widening to `255` would be safe but was not applied per “no shrinking” yet snapshot is `150`; widening requires a future `015` if desired.
- `unsubscribed_at` type remains `TIMESTAMP` on DBs that ran `008` before `010`; snapshot says `DATETIME` — no conversion applied per approval, so type remains heterogeneous until a future migration.
- `010` replay still fails on empty DB — document says not supported; fresh installs must use snapshot.
- `wishlist.idx_wishlist_user` not created — correct per approval but `007` history still lists it.
