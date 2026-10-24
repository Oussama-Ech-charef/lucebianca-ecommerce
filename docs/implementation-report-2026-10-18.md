# Luce Bianca - Implementation Report (2026-10-18)

Status: implementation report for the uncommitted working-tree changes present at the time of writing.

This report was produced without changing application source, styles, configuration, dependencies, tests, database contents, or backups. The only file created during report authoring is this report. No commit, push, migration, or database mutation was performed.

## Report Basis

| Item | Value |
|---|---|
| Workspace | `C:\Users\ULTRA PC\Desktop\lucebianca` |
| Agent configuration | `C:\Users\ULTRA PC\Desktop\lucebianca\.agent` |
| Project | Same as workspace root |
| Branch | `main` |
| HEAD | `bc6130e28ef33e8756de73d728ae9f133fd74e02` |
| HEAD subject | `docs(analysis): add post-restoration E2E audit and update documentation index` |
| Report target | `docs/implementation-report-2026-10-18.md` (did not exist before this report) |
| Main configured database | `lucebianca` (read-only metadata inspection only) |
| Working tree | Dirty; all implementation changes are uncommitted |

### Evidence labels used in this report

- **Current**: rerun or inspected during report authoring.
- **Prior**: recorded earlier in the implementation session or in a prior audit artifact; not rerun during report authoring.
- **Static**: source inspection, syntax check, type check, or lint; not proof of runtime behavior.
- **Runtime**: behavior exercised by a test harness or server.
- **Unverified**: not executed or no evidence retained.

The report distinguishes source-present behavior from runtime-verified behavior. UI behavior is not described as working merely because the component exists.

### Scope delta

No unapproved capability was added. The work maps to the audit roadmap's Phase 1 (search, honeypot, hero, cookie consent), Phase 2 (coupons), and Phase 3 (backup script, admin accounts) items.

Two scope clarifications matter:

1. The contact honeypot was already present in the current source. This work added regression coverage and did not reimplement it.
2. Admin account management implements equal-operator list/create/update/delete with guarded deletion. It does not implement a super-admin role or an account deactivation flag because the current schema and auth model have no such fields. This is a partial match to the roadmap's wording, not a hidden expansion.

---

## 1. Executive Summary

The working tree contains a substantial, coherent implementation of the outstanding audit roadmap. The main deliverables are:

- Live, debounced storefront search using the existing product endpoint.
- End-to-end coupon support: admin CRUD, checkout preview, authoritative order calculation, order snapshots, and display in admin, confirmation, email, and WhatsApp paths.
- Admin account management in Settings, with duplicate-email, self-delete, last-admin, and deleted-token checks.
- Automated gzip database backup script with locking, atomic rename, retention, and credential-safe invocation.
- Storefront cookie notice with localStorage acknowledgement.
- Environment-driven hero video integration with poster, reduced-motion, and error fallback.
- Regression coverage for the already-existing contact honeypot.

### Current status by workstream

| Workstream | Implementation | Verification status |
|---|---|---|
| Live search | Implemented in source | API behavior covered by prior integration suite; no browser check |
| Coupons | Implemented in source | API and order behavior covered by prior integration suite; main DB migration pending |
| Admin accounts | Implemented in source | API behavior covered by prior integration suite; no browser check |
| Backup script | Implemented in source | Exercised by prior integration suite; no scheduler installed |
| Cookie notice | Implemented in source | No browser/visual check |
| Hero video integration | Implemented in source | No asset and no browser check; fallback is static only |
| Contact honeypot | Already existed; tests added | Prior integration suite covers reject-and-no-store behavior |
| Multi-region shipping | Not started | Blocked on business rate data |
| Abandoned cart | Not started | Optional roadmap item |

### Verification snapshot

- **Current static checks**: `npx tsc --noEmit --incremental false` passed with no output; `npx eslint . --no-cache` passed with no output; `php -l` passed on all 19 changed/new PHP files.
- **Prior integration evidence**: the final retained API test artifact reports **324/324 passed** in 10.248 seconds on PHP 8.5.1 using isolated database `lucebianca_test`. This suite was not rerun during report authoring.
- **Prior baseline audit**: **118/118 unit tests** and **227/227 API tests** passed before this implementation. Those are historical baseline results, not current reruns.
- **Current database state**: the configured `lucebianca` database has 19 tables, 20 recorded migrations, no order coupon columns, and migration `021_add_orders_coupon_fields.sql` is not recorded.
- **Browser/visual verification**: not performed. The repository has no automated browser E2E configuration, as documented in `api/tests/README.md`.

### Critical handover warning

The current source expects the three coupon snapshot columns on `orders`, but the configured main database does not have them yet. Deploying this working tree against the current main database before applying migration 021 would break order placement and the admin order queries that explicitly select `coupon_code` and `discount_amount`. This is inferred from the current source and the read-only schema inspection; it was not executed against the main database.

The correct order is: review the diff, take a verified backup, apply migration 021 to a staging clone, verify, then apply it to production under a write freeze, and only then run the new application code. Migration 021 is additive and nullable/defaulted, so the old application code should remain compatible after migration, but that backward-compatibility claim was not runtime-tested.

### Overall assessment

The implementation covers the codeable portions of the approved Phase 1-3 roadmap, and the prior API suite provides strong behavioral evidence. It is not release-ready until migration 021 is applied to the target database, the hero asset is supplied, the backup schedule is installed and restore-tested, and the main UI flows receive browser QA.

---

## 2. Original Requirements and Audit Findings

### Sources inspected

- `lucebianca-project-full.md`: original specification, including mandatory security, hero, notification, backup, cookie consent, and feature requirements.
- `comprehensive_audit_and_roadmap.md`: baseline audit and prioritized completion roadmap. This document was already untracked before the implementation work; it is not an implementation file.
- `docs/analysis/2026-10-18-database-restoration-and-e2e-audit.md`: prior restoration and end-to-end audit.
- `docs/runbooks/backup-and-restore.md`, `database/migrations/README.md`, `api/tests/README.md`, and `database/schema.sql`.
- Current Git diff, test reports, and source files.

### Audit baseline

The audit recorded a clean working tree and these baseline results:

- `php api/tests/run-unit-tests.php`: 118/118 passed.
- `php api/tests/run-api-tests.php`: 227/227 passed against isolated `lucebianca_test`.
- `npx tsc --noEmit`: passed.
- `npm run lint`: passed.
- `php api/scripts/migrate.php --dry-run`: 20/20 migrations applied.

Those results are **Prior** evidence from the audit. They describe the pre-implementation baseline, not the current dirty working tree. The current API suite reports 324 tests; the difference is consistent with the coverage added for this work, but the two totals are not directly comparable.

### Findings and current resolution

| Audit finding | Requirement context | Resolution in current source |
|---|---|---|
| Incomplete live header search | Audit Part 6, finding 1; roadmap 1.1 | Implemented: header uses the existing `/api/products` endpoint with a `search` filter, 300 ms debounce, and result links. |
| Missing hero video and fallback | Spec 3.6 and roadmap 1.3 | Integration implemented: env-driven `<video>` plus poster, reduced-motion handling, and error fallback. No video asset exists. |
| Orphaned coupons table and missing discount workflow | Audit Part 6, finding 3; Part 7, item 1; roadmap 2.1-2.3 | Implemented: admin CRUD, public preview, checkout UI, authoritative order calculation, snapshots, migration 021. Main DB migration remains pending. |
| Contact form bot vulnerability | Spec 3.1 and roadmap 1.2 | Audit finding is stale. The current source already has a hidden `website` honeypot in `frontend/src/app/contact/contact-view.tsx` and `api/src/Controllers/ContactController.php`. Regression tests were added; source behavior was retained. |
| Missing cookie consent banner | Decision table and roadmap 1.4 | Implemented in `frontend/src/components/cookie-banner.tsx`, mounted by `storefront-shell.tsx`; no browser check. |
| Admin account management missing | Audit Part 7, item 5; roadmap 3.2 | Implemented list/create/update/delete in Settings. Deactivation and super-admin roles remain unsupported by schema/auth. |
| Automated daily backups missing | Spec 3.8 and roadmap 3.1 | Backup script implemented and covered by the prior API suite. Scheduling is not installed. |
| Multi-region shipping missing | Audit Part 7, item 6 | Not started. Requires region/rate business data. |
| Abandoned cart follow-up missing | Audit Part 7, item 8 | Not started. Optional roadmap item. |

### Honeypot finding correction

The audit referenced `frontend/src/app/contact/contact-form.tsx`, which does not exist. The actual form is `frontend/src/app/contact/contact-view.tsx`; it already contains a `website` honeypot field. `ContactController` rejects a filled honeypot with a generic 422 and does not store the message. The implementation work therefore added tests rather than new source behavior.

The roadmap's suggested acceptance criterion said "return 200 OK without saving." The current implementation returns 422 with a generic validation error. That deviation is deliberate: it avoids telling a bot that its submission was detected while still storing nothing. The test suite asserts the current behavior.

### Requirements mapping

- **PDO and prepared statements**: new coupon and product-search queries use PDO prepared statements with bound values.
- **CSRF**: the public coupon preview and all admin coupon/account mutations use the existing CSRF middleware.
- **Auth and authorization**: admin coupon/account routes use `AuthMiddleware('admin')`; state-changing routes also use CSRF.
- **Validation**: coupon values, codes, expiry, admin names/emails/passwords, and search input are validated server-side.
- **Hero**: video is optional and environment-driven; the existing CSS/logo media layer remains the fallback.
- **Backup**: the script is cron-compatible and supports a configurable directory and retention; off-machine copying is documented in the runbook but not automated, and no scheduler ships in the repository.
- **Cookie consent**: disclosure and acknowledgement are implemented; no optional analytics or advertising trackers are present today.

---

## 3. Detailed Implementation History

This history is reconstructed from the current Git diff, the working-tree file inventory, the audit document, and retained test artifacts. It describes what is present now, not a commit-by-commit chronology.

### 3.1 Baseline and constraints

- The audit established a clean baseline with 20 applied migrations, 118 unit tests, and 227 API tests.
- The main database is still at that 20-migration baseline; migration 021 has not been applied.
- The implementation was performed directly in the working tree and remains uncommitted.
- Database-mutating tests were run against isolated test databases and the test database was dropped afterward.

### 3.2 Live search

The header search was upgraded from a static "coming soon" message to a live search overlay. The design choice was to extend the existing public product listing endpoint with a `search` filter rather than create a duplicate search endpoint. The same filter was also exposed on the shop page so a search from the header can be continued as a crawlable `/shop?search=...` result page.

Implementation points:

- 2-character minimum, 300 ms debounce, six results in the dropdown.
- Request sequence guard prevents an older in-flight response from overwriting a newer query.
- Loading, empty, error, and no-match states.
- Product thumbnails, prices, sale prices, and links.
- Mobile search entry through the existing mobile menu.
- Shop filter panel preserves the search term alongside category, size, color, and price filters.
- Existing product cache is keyed by all filters, including search.

### 3.3 Coupons

The coupon work was implemented in four layers:

1. **Database**: migration 021 adds order-level coupon ID, code snapshot, and discount amount. The existing `coupons` table remains the source of truth for code, type, value, expiry, and active flag.
2. **Backend**: `CouponRepository`, `CouponService`, public `CouponController`, admin `CouponsController`, and order integration in `OrderService`.
3. **Checkout**: coupon apply/remove UI, preview totals, and authoritative order revalidation.
4. **Presentation**: discount rows in checkout, order confirmation, admin order detail, confirmation email, admin notification, and the WhatsApp message.

Decision points:

- Only schema-supported fields are used. No usage limits, per-customer limits, or minimum-spend rules were invented.
- Shipping is calculated on the pre-discount merchandise subtotal.
- Discounts apply only to merchandise and are capped at the merchandise subtotal.
- The preview endpoint recomputes live prices and stock; the order placement path independently revalidates the coupon under `SELECT ... FOR UPDATE`.
- Order rows store both the coupon ID and a code/discount snapshot, so deleting a coupon does not destroy historical order detail.

### 3.4 Admin accounts

Admin account management was added as an "Accounts" tab in `/admin/settings`.

- List accounts.
- Create an account with name, email, and password.
- Update name, email, and/or password; an empty password field means "leave unchanged."
- Delete an account.
- Duplicate email returns 409.
- Self-deletion returns 409.
- Last-admin deletion is guarded under a table-wide row lock.
- `AuthMiddleware` now verifies that the admin ID in the token still exists on every admin request, so a deleted admin's access token stops working immediately.
- The first admin still has to be seeded offline with `api/scripts/create-admin.php`; there is no public admin registration.
- Roles and deactivation flags are intentionally not editable because the schema has no active flag and every admin is an equal operator.

### 3.5 Backup automation

`api/scripts/backup-db.php` was added as a CLI-only backup runner.

- Reads `DB_*` settings from the API environment.
- Uses `mysqldump --single-transaction --quick` and gzip streaming.
- Writes to a `.tmp` file and renames only on success.
- Uses a non-blocking lock to prevent overlapping runs.
- Passes credentials through a temporary MySQL defaults-extra-file, not process arguments.
- Prunes only generated files matching `lucebianca_YYYYmmdd_HHMMSS.sql.gz`; manually named backups are preserved.
- Defaults to `backups/` and 30-day retention.
- Supports `LB_TEST_ENV_FILE` for isolated tests.
- Documents the schedule in `docs/runbooks/backup-and-restore.md`; no scheduler is installed by the repository.

### 3.6 Cookie consent

A storefront-only cookie notice was added.

- `localStorage` key: `lucebianca:cookie-consent:v1`.
- The acknowledgement is stored as an ISO timestamp.
- If storage is blocked, the notice still renders for the current page view and the storefront does not fail.
- Copy states that only essential cookies/local storage are used and that no advertising or analytics cookies are used.
- Links to the existing `/privacy` page.
- Mounted in `storefront-shell.tsx`, so admin routes do not show it.

### 3.7 Hero video integration

The hero now supports optional background video and poster URLs through:

- `NEXT_PUBLIC_HERO_VIDEO_URL`
- `NEXT_PUBLIC_HERO_VIDEO_POSTER`

Behavior:

- The video is autoplaying, muted, looping, inline, and metadata-preloaded.
- A poster is shown when configured.
- `prefers-reduced-motion` disables the video and shows the poster when available.
- A video error hides the video and falls back to the poster or the existing CSS/logo media layer.
- No approved video asset exists in the repository. `frontend/public/` contains `logo.png` and generic starter SVGs, not a hero video.

This is an integration, not a completed visual feature, until an approved asset and poster are supplied.

### 3.8 Contact honeypot coverage

No frontend or controller change was needed. The existing `website` field was already hidden from humans and checked by `ContactController`. New API assertions cover:

- A filled honeypot returns 422 with a generic message.
- No contact message is stored.
- A valid submission still stores exactly one message.

### 3.9 Documentation, tests, and hardening

- `database/migrations/README.md` was updated for migration 021 and fresh-install guidance.
- `docs/runbooks/backup-and-restore.md` was expanded for the new script, retention, scheduling, and restore checks.
- `frontend/.gitignore` was changed to unignore `.env.example`; `frontend/.env.example` now documents the public site, API, Google, and hero variables.
- `api/.env.example` documents the backup variables.
- `api/tests/run-api-tests.php` gained coverage for search, coupons, migration 021, the contact honeypot, admin accounts, and the backup script.
- An intermediate test run is retained as a failure artifact: 315/317 at `2026-10-18T21:02:27Z`, with two coupon-snapshot tests failing. The final retained run is 324/324 at `2026-10-18T21:10:18Z`. The final source includes the snapshot behavior; the failure artifact remains for audit purposes.

### Key decisions

| Area | Decision |
|---|---|
| Search API | Extend `/api/products` with `search`; do not add a duplicate endpoint. |
| Contact honeypot | Preserve existing behavior and generic 422; add tests only. |
| Cookie storage | `lucebianca:cookie-consent:v1`; disclosure covers essential storage only. |
| Coupon schema | Use only existing coupon fields; no invented minimum/usage limits. |
| Coupon migration | Add `coupon_id`, `coupon_code`, `discount_amount` to orders; `ON DELETE SET NULL`. |
| Shipping threshold | Evaluate on pre-discount merchandise subtotal. |
| Coupon validation | Preview and order placement both compute authoritative totals; order placement locks the coupon row. |
| Admin coupons | `/admin/coupons` with list/create/edit/deactivate/delete. |
| Admin accounts | Equal-operator CRUD plus guarded deletion; no roles/deactivation. |
| Backup defaults | `backups/`, 30-day retention, filename-only pruning. |
| Hero media | Environment-driven video with CSS/logo fallback; no asset shipped. |

---

## 4. File-by-File Inventory

### Inventory method and scope

- `git status --short --untracked-files=all`
- `git -c core.safecrlf=false diff --stat`
- `git -c core.safecrlf=false diff --numstat`
- Filesystem checks with `Get-ChildItem`
- Git-ignored/generated files are excluded from the 42-file implementation count and are called out separately.

At inventory time:

- **29 tracked files modified**.
- **13 new implementation files** (untracked).
- **42 implementation paths total**.
- **1 additional untracked document** (`comprehensive_audit_and_roadmap.md`) existed before this implementation and is not counted as implementation.
- **0 deleted or renamed paths** in Git status.
- The report file created by this task is not part of the 42 and is the only file created during report authoring.

### API and configuration

| Path | Change | Purpose / status |
|---|---|---|
| `api/.env.example` | M | Documents `MYSQLDUMP_BIN`, `DB_BACKUP_DIR`, and `DB_BACKUP_RETENTION_DAYS`; no secret values. |
| `api/routes.php` | M | Adds public coupon preview and admin coupon/account routes; updates admin bootstrap comments. |
| `api/src/Controllers/OrderController.php` | M | Passes optional `coupon_code` from checkout to `OrderService::placeOrder`. |
| `api/src/Controllers/ProductController.php` | M | Reads and truncates the `search` query, includes it in filters and cache key. |
| `api/src/Core/AuthMiddleware.php` | M | Verifies the admin account still exists on every admin request. |
| `api/src/Models/Order.php` | M | Hydrates and serializes `coupon_id`, `coupon_code`, and `discount_amount`. |
| `api/src/Repositories/AdminRepository.php` | M | Adds account listing, creation, update, duplicate-email check, count, and guarded deletion. |
| `api/src/Repositories/OrderRepository.php` | M | Inserts and selects coupon snapshot fields for order detail and admin lists. |
| `api/src/Repositories/ProductRepository.php` | M | Adds escaped literal search across product name, description, and category name. |
| `api/src/Services/EmailService.php` | M | Adds discount row to the order confirmation HTML. |
| `api/src/Services/NotificationService.php` | M | Adds discount line to the admin new-order notification. |
| `api/src/Services/OrderService.php` | M | Adds cart quote, coupon validation/locking, totals policy, snapshots, and side-effect data. |
| `api/tests/run-api-tests.php` | M | Adds 605 lines covering search, coupons, migration 021, honeypot, accounts, and backup. |
| `api/scripts/backup-db.php` | A | Automated gzip backup, lock, retention, temporary credentials, failure cleanup. |
| `api/src/Controllers/Admin/AccountsController.php` | A | Admin account list/create/update/delete API. |
| `api/src/Controllers/Admin/CouponsController.php` | A | Admin coupon CRUD and validation. |
| `api/src/Controllers/CouponController.php` | A | Public `POST /api/coupons/validate` preview. |
| `api/src/Models/Coupon.php` | A | Coupon entity and API serialization. |
| `api/src/Repositories/CouponRepository.php` | A | Coupon lookup, locked lookup, duplicate check, create/update/delete. |
| `api/src/Services/CouponService.php` | A | Percentage/fixed discount arithmetic with defensive caps. |

### Database and documentation

| Path | Change | Purpose / status |
|---|---|---|
| `database/migrations/021_add_orders_coupon_fields.sql` | A | Adds order coupon columns, index, and `ON DELETE SET NULL` FK with idempotence guards. |
| `database/migrations/README.md` | M | Updates fresh-install/upgrade guidance for 021. |
| `database/schema.sql` | M | Adds the three order columns, index, and FK to the fresh-install snapshot. |
| `docs/runbooks/backup-and-restore.md` | M | Documents the backup script, retention, scheduling example, and restore checks. Contains a stale log path in the cron example; see Section 8. |

### Frontend

| Path | Change | Purpose / status |
|---|---|---|
| `frontend/.gitignore` | M | Unignores `.env.example`. |
| `frontend/.env.example` | A | Documents public site/API URLs, optional Google client ID, and hero video/poster variables with empty values. |
| `frontend/src/app/admin/(protected)/admin-shell.tsx` | M | Adds the Coupons admin navigation item. |
| `frontend/src/app/admin/(protected)/coupons/page.tsx` | A | Coupons admin page shell. |
| `frontend/src/app/admin/(protected)/coupons/coupons-client.tsx` | A | Coupon list, create/edit form, active toggle, expiry formatting, delete. |
| `frontend/src/app/admin/(protected)/orders/orders-client.tsx` | M | Displays coupon code and discount in admin order detail. |
| `frontend/src/app/admin/(protected)/settings/settings-client.tsx` | M | Adds the Accounts tab and hides the store-settings save bar on that tab. |
| `frontend/src/app/admin/(protected)/settings/accounts-tab.tsx` | A | Admin account list/create/edit/delete UI with self-delete disabled. |
| `frontend/src/app/checkout/checkout-form.tsx` | M | Coupon apply/remove, preview totals, discount display, and WhatsApp discount line. |
| `frontend/src/app/order-confirmation/page.tsx` | M | Displays discount and coupon code on the confirmation page. |
| `frontend/src/app/shop/filter-panel.tsx` | M | Adds a search field and preserves it through filter navigation and clear. |
| `frontend/src/app/shop/page.tsx` | M | Passes the `search` query parameter into product fetching and filter state. |
| `frontend/src/components/header.tsx` | M | Debounced live search overlay, mobile search entry, result states, and view-all link. |
| `frontend/src/components/hero.tsx` | M | Adds optional video/poster, reduced-motion behavior, and error fallback. |
| `frontend/src/components/cookie-banner.tsx` | A | Storefront cookie notice with localStorage acknowledgement. |
| `frontend/src/components/storefront-shell.tsx` | M | Mounts the cookie notice only on storefront routes. |
| `frontend/src/lib/storefront.ts` | M | Adds `search` to product queries, `previewCoupon`, and optional `coupon_code` on order placement. |
| `frontend/src/lib/types.ts` | M | Adds `Coupon`, `CouponPreview`, and order coupon fields. |

### Pre-existing untracked document

| Path | Change | Purpose / status |
|---|---|---|
| `comprehensive_audit_and_roadmap.md` | Untracked, pre-existing | Baseline audit and roadmap. It is not an implementation file and was not modified during report authoring. |

### Generated and ignored artifacts

Still present but excluded from the 42-file count:

- `api/storage/test-reports/latest-report.md` and `latest-report.json`: final 324/324 report.
- `api/storage/test-reports/api-20261018-210227Z.md` and `.json`: retained 315/317 failure report.
- `api/storage/logs/app-error.log`: runtime log written by prior API tests; not read for this report.
- `backups/`: Git-ignored; two pre-existing manually named dumps remain untouched. No generated dump was added during report authoring.
- `frontend/tsconfig.tsbuildinfo`: Git-ignored TypeScript incremental state; not part of source.
- Existing `lucebianca_test_e2e` database: pre-existing isolated test database, not created or inspected during this work.

No generated backup, test database, or temporary fixture from the final test run remains in the repository. The final API runner drops its `lucebianca_test` database and its scratch migration databases.

---

## 5. Feature Details

### 5.1 Live storefront search

**Frontend**

- `frontend/src/components/header.tsx`
- Search opens in an overlay from the desktop search button and the mobile menu.
- Queries shorter than two trimmed characters are not sent.
- Input is debounced by 300 ms.
- Requests use `fetchProducts({ search: term, per_page: "6" })`.
- A monotonically increasing request ID prevents an older response from replacing newer results.
- The overlay shows:
  - Initial guidance.
  - "Keep typing" state below two characters.
  - Loading state.
  - Empty results.
  - Error state if the request fails.
  - Product rows with image, name, price, sale price, and link.
  - A "View all results" link to `/shop?search=...`.
- The shop filter panel now includes a search field and preserves it when applying or clearing other filters.

**Backend**

- `api/src/Controllers/ProductController.php` reads `search`, trims it, and caps it at 100 characters.
- `api/src/Repositories/ProductRepository.php` uses a literal substring search:
  - Product name.
  - Description.
  - Category name.
- `%`, `_`, and the escape character `!` are escaped so user input cannot act as a SQL wildcard.
- Search is combined with the existing active-product filter, category/size/color/price filters, pagination, and image loading.
- The cache key includes page, per-page, and all filters, including search.
- Admin product mutations already clear the product cache, so new search result pages are not left stale by product edits.

**Evidence**

- Prior API tests cover name, description, category, case-insensitive matching, no-match empty pages, and literal `%` handling.
- No browser test covers the overlay, keyboard navigation, or mobile behavior.

### 5.2 Coupons

**Admin API and UI**

- Routes:
  - `GET /api/admin/coupons`
  - `POST /api/admin/coupons`
  - `PUT /api/admin/coupons/{id}`
  - `DELETE /api/admin/coupons/{id}`
- UI: `/admin/coupons`, linked from the admin navigation.
- Fields: `code`, `discount_type` (`percentage` or `fixed`), `discount_value`, `expires_at`, `is_active`.
- Codes are trimmed and uppercased. Validation accepts `[A-Z0-9_-]` with length 2-40.
- Percentage values must be greater than 0 and at most 100.
- Fixed values must be greater than 0 and at most 99,999,999.99, with at most two decimals.
- Duplicate codes are rejected case-insensitively.
- Expiry accepts `YYYY-MM-DDTHH:MM`, `YYYY-MM-DDTHH:MM:SS`, `YYYY-MM-DD HH:MM`, or `YYYY-MM-DD HH:MM:SS`.
- Admins can deactivate a coupon without deleting it, which preserves history.
- Delete is guarded by existence check and returns 204.

**Public preview**

- Route: `POST /api/coupons/validate`, protected by the existing CSRF middleware.
- Input: `code` and cart `items` as `variant_id`/`quantity`.
- The server resolves current prices and stock before calculating.
- Returns the canonical coupon code/type/value and authoritative subtotal, discount, shipping, and total.
- Invalid or expired code returns 422 with a `coupon_code` field error.
- Unavailable cart line or insufficient stock returns 409.
- The endpoint is not behind a dedicated rate limiter; see Section 8.

**Order placement**

- `OrderService::quoteCart()` computes a non-persistent authoritative quote for preview.
- `OrderService::placeOrder()` independently rechecks lines, computes the subtotal, and re-reads the coupon with `findValidByCodeForUpdate()` inside the transaction.
- Shipping is calculated from the pre-discount merchandise subtotal.
- The discount is capped at the merchandise subtotal.
- The total is `subtotal - discount + shipping`, never below zero.
- Invalid or expired coupons reject the order rather than silently dropping the discount.
- The order row stores:
  - `coupon_id` (nullable link).
  - `coupon_code` (immutable snapshot).
  - `discount_amount` (authoritative amount).
- If the coupon is later deleted, the FK sets `coupon_id` to NULL while the code and discount snapshot remain.

**Checkout and display**

- `frontend/src/app/checkout/checkout-form.tsx` handles apply/remove and displays the discount row.
- The checkout sends `coupon_code` only when a preview is active.
- The order confirmation page, admin order detail, confirmation email, admin notification, and WhatsApp message all display the coupon code and discount when present.
- Old orders without coupons display no discount row or a 0.00 amount.

**Known limits**

- No minimum-spend rule.
- No per-customer or global usage limit.
- No stacking or automatic coupon application.
- No coupon reporting beyond the order snapshot/index.

These are schema/spec limits, not accidental omissions.

### 5.3 Admin account management

**UI**

- New Accounts tab in `/admin/settings`.
- Lists name, email, role, and join date.
- Marks the current account as "You."
- Create, edit, and delete flows.
- Self-delete is disabled in the UI and rejected server-side.
- Password field is required on create and optional on edit.
- Native confirmation prompt before delete.

**API**

- `GET /api/admin/accounts`
- `POST /api/admin/accounts`
- `PUT /api/admin/accounts/{id}`
- `DELETE /api/admin/accounts/{id}`

**Validation and guards**

- On create, name, email, and password are required; update accepts partial fields.
- Name is limited to 2-100 characters and email to 150 characters.
- Email is normalized and checked case-insensitively for duplicates.
- Password byte length is 8-200 (`strlen`; ASCII-equivalent for typical passwords).
- Self-deletion returns 409.
- Last-admin deletion returns 409.
- Missing target on delete returns 409 with "Admin account not found."
- Deletion happens under a table-wide `SELECT ... FOR UPDATE` guard, preventing two concurrent deletions from removing all admins.
- `AuthMiddleware` checks the admin row on every admin request; a deleted admin's JWT stops working immediately.
- Refresh tokens cascade on account deletion through the schema FK.

**Known limits**

- No roles or permissions. All admins are equal operators.
- No deactivation flag; removal is deletion.
- No super-admin.
- Password changes do not revoke already-issued access or refresh tokens. See Section 8.
- The first admin must still be seeded with `api/scripts/create-admin.php`.

### 5.4 Automated backup script

`api/scripts/backup-db.php`:

- Refuses non-CLI execution.
- Loads `api/.env` or `LB_TEST_ENV_FILE`.
- Reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- Supports `MYSQLDUMP_BIN`, `DB_BACKUP_DIR`, and `DB_BACKUP_RETENTION_DAYS`.
- Defaults to `backups/` and 30 days.
- Uses `mysqldump --single-transaction --quick`.
- Streams through gzip to a `.tmp` file and renames only after success.
- Uses a non-blocking lock file.
- Writes credentials to a temporary MySQL defaults-extra-file, removes it in `finally`, and never puts them in process arguments.
- Prunes only generated filenames matching the expected timestamp pattern.
- Preserves manually named backups.
- Returns 0 on success or when another run holds the lock; 1 on failure.
- Removes partial `.tmp` files on failure.

The prior API suite exercises the script against the isolated test database, including gzip magic, orders table content, retention, manual-file preservation, failure behavior, `.tmp` cleanup, and password non-disclosure.

### 5.5 Cookie notice

- File: `frontend/src/components/cookie-banner.tsx`.
- Key: `lucebianca:cookie-consent:v1`.
- Acknowledgement value: ISO timestamp.
- Message: essential cookies/local storage keep cart, session, and preferences working; no advertising or analytics cookies.
- Button: "Accept & continue."
- Link: `/privacy`.
- Storage failure is caught; the notice still renders for the current view and acceptance still dismisses it.
- Mounted only by `storefront-shell.tsx`, so admin routes are excluded.
- No consent categories, cookie settings, or revocation UI, because no optional trackers are present.

### 5.6 Hero video integration

- File: `frontend/src/components/hero.tsx`.
- Variables:
  - `NEXT_PUBLIC_HERO_VIDEO_URL`
  - `NEXT_PUBLIC_HERO_VIDEO_POSTER`
- Video attributes: `autoPlay`, `muted`, `loop`, `playsInline`, `preload="metadata"`, `aria-hidden`, `tabIndex={-1}`, `disablePictureInPicture`.
- `onError` hides the video and falls back.
- `useReducedMotion()` disables video playback for reduced-motion visitors.
- Poster is rendered as a CSS background if the video is absent or failed.
- Existing CSS/logo media remains when no poster is available.
- No video file exists in `frontend/public/`. The asset is a deployment input, not a finished deliverable.

### 5.7 Contact honeypot

- Existing frontend: `frontend/src/app/contact/contact-view.tsx` has a `website` state and sends it with the payload.
- Existing backend: `api/src/Controllers/ContactController.php` checks the field and returns a generic validation response without storing.
- Existing protections remain: CSRF and contact rate limiting.
- New tests verify reject-and-no-store behavior and a valid single-message submission.
- No source change was made to the honeypot during this work.

### 5.8 Cross-cutting integration

- `frontend/src/lib/types.ts` now models coupon previews and order coupon fields.
- `frontend/src/lib/storefront.ts` adds `previewCoupon()` and optional `coupon_code` to order placement.
- `api/src/Models/Order.php` and `api/src/Repositories/OrderRepository.php` carry the new fields through reads and writes.
- `api/src/Services/EmailService.php` escapes coupon values before inserting them into the HTML email.
- `api/src/Services/NotificationService.php` includes the discount in the admin notification.
- `frontend/src/app/checkout/checkout-form.tsx` includes the discount in the WhatsApp confirmation text.
- `frontend/src/app/admin/(protected)/admin-shell.tsx` exposes the Coupons screen.
- `frontend/src/app/admin/(protected)/settings/settings-client.tsx` exposes the Accounts tab without showing the general settings save button.

---

## 6. Database and Data Safety

### Migration 021 summary

`database/migrations/021_add_orders_coupon_fields.sql` is additive:

| Object | Definition / behavior |
|---|---|
| `orders.coupon_id` | `INT UNSIGNED NULL`; links to `coupons.id`. |
| `orders.coupon_code` | `VARCHAR(40) NULL`; immutable snapshot of the code used. |
| `orders.discount_amount` | `DECIMAL(10,2) NOT NULL DEFAULT 0.00`; authoritative discount. |
| `idx_orders_coupon` | Index supporting the FK and coupon reporting. |
| `fk_orders_coupon` | `ON DELETE SET NULL`; deleting a coupon preserves order snapshots. |

The migration uses `INFORMATION_SCHEMA` checks and prepared statements so re-running is a no-op. It was tested on a minimal pre-021 scratch schema, including the FK behavior and idempotent re-run.

### Fresh-install schema

`database/schema.sql` now defines the three order columns and the coupon index/FK. Because `orders` is created before `coupons`, the fresh-install file adds the FK after the `coupons` table exists. The snapshot still has 19 tables; migration 021 adds columns, not a table.

### Current database state

Read-only inspection of the configured `lucebianca` database returned:

| Check | Result |
|---|---|
| MySQL version | 8.0.33 |
| Base tables | 19 |
| `schema_migrations` rows | 20 |
| `021_add_orders_coupon_fields.sql` recorded | 0 |
| `orders` coupon columns present | 0 |

This confirms the main database was not migrated during implementation. It also confirms the critical deployment blocker: the working-tree code is ahead of the configured database.

### Data preservation

- Existing orders are not rewritten by migration 021.
- Existing order rows receive NULL coupon IDs/codes and 0.00 discount by column defaults.
- Deleting a coupon affects only `orders.coupon_id`; `coupon_code` and `discount_amount` remain.
- This was tested for both direct order snapshots and admin order detail after deletion.
- There is no down migration. Rollback is by restoring the pre-migration backup, or by manually dropping the FK/index/columns after review.

### Test isolation

- The prior API suite rebuilt only `lucebianca_test` from `database/schema.sql`.
- Migration 021 was applied only to isolated `lucebianca_test_migrate_coupon021` scratch databases.
- The final runner drops `lucebianca_test` and scratch migration databases.
- A separate `lucebianca_test_e2e` database exists from earlier end-to-end work; it was not created, dropped, or inspected during this report.
- No migration or database mutation was performed during report authoring.

### Backup and confidential data

- `backups/` is Git-ignored and contains two pre-existing manually named dumps. They were not opened, copied, or modified.
- No new backup was created by the implementation or report authoring. Backup-script tests used an isolated temporary directory and cleaned it.
- Test reports deliberately exclude raw request/response bodies because they may contain tokens, emails, or customer data.
- This report contains no passwords, API keys, JWT secrets, access tokens, customer records, or private customer information.
- The runtime log `api/storage/logs/app-error.log` exists and may contain operational data; it was not read for this report.

### Timezone caveat

Coupon expiry is compared with MySQL `NOW()` in `CouponRepository`, while the admin expiry parser interprets input in PHP's application timezone. In the current environment both resolve to the same wall-clock time, but production must keep the MySQL session timezone and the application timezone consistent. Otherwise an expiry can be off by the offset. This is an operational assumption, not a reproduced defect.

---

## 7. Testing and Verification

### Current checks performed during report authoring

| Check | Label | Environment | Result | Evidence |
|---|---|---|---|---|
| `npx tsc --noEmit --incremental false` | Current / static | `frontend/`, Node 24.14.0, npm 11.9.0 | Passed, no output | TypeScript check; `incremental` disabled to keep the check read-only. |
| `npx eslint . --no-cache` | Current / static | `frontend/`, Node 24.14.0 | Passed, no output | ESLint check with cache disabled. |
| `php -l <path>` over 19 changed/new PHP files | Current / static | PHP 8.5.1 ZTS | 19/19 "No syntax errors detected" | PowerShell `foreach` loop; files are the API paths in Section 4 plus `api/tests/run-api-tests.php`. |
| Read-only PDO introspection | Current / runtime (metadata) | MySQL 8.0.33 | 19 tables; 20 migrations; 0 order coupon columns; 021 not recorded | `App\Core\Database::get()` queries against the configured database. |
| `git status --short --untracked-files=all` | Current / inventory | Git working tree | 29 modified, 13 new implementation paths, 1 pre-existing untracked audit document | No deleted or renamed paths. |
| `git diff --stat` / `git diff --numstat` | Current / inventory | Git working tree | 29 files changed, 1,561 insertions, 107 deletions in tracked files; plus the new untracked implementation files | Used for the inventory counts. |

### Prior checks not rerun during report authoring

| Check | Label | Environment / scope | Result | Evidence |
|---|---|---|---|---|
| `php api/tests/run-api-tests.php` | Prior / runtime | PHP 8.5.1; isolated `lucebianca_test`; rebuilt from schema snapshot | **324/324 passed**, 0 failed, 0 skipped; 10.248s | `api/storage/test-reports/latest-report.md` and `.json`, started `2026-10-18T21:10:18+00:00`. |
| Earlier API run | Prior / runtime | Same harness | 315/317 passed; 2 failures | `api/storage/test-reports/api-20261018-210227Z.md`: coupon snapshot after deletion and admin order detail after deletion. |
| `php api/tests/run-unit-tests.php` | Prior (audit) | PHP 8.5.1, no DB | 118/118 passed | `comprehensive_audit_and_roadmap.md`, Part 10. Not rerun. |
| Baseline API suite | Prior (audit) | Isolated `lucebianca_test` | 227/227 passed | `comprehensive_audit_and_roadmap.md`, Part 10. Not current. |
| `php api/scripts/migrate.php --dry-run` | Prior (audit/implementation) | Main/scratch database context | 20/20 applied at baseline; 021 later tested on scratch | Audit Part 10; implementation session. No stored command output retained. Current read-only DB query independently confirms 021 pending. |
| HTTP smoke checks | Prior (implementation session) | Local HTTP | Reported passed in the session summary | No retained artifact; not relied on as verification evidence here. |

### What the prior 324-test suite covers

The final API suite is a real HTTP harness against the built-in PHP server and an isolated MySQL database. Relevant coverage includes:

- Search:
  - Fixture category and product.
  - Name match.
  - Case-insensitive match.
  - Description match.
  - Category-name match.
  - No-match empty result.
  - Literal `%` handling.
- Coupons:
  - Fixture product and variant.
  - Admin create/list/update/delete.
  - Duplicate code case-insensitivity -> 409.
  - Invalid code characters -> 422.
  - Percentage above 100 -> 422.
  - Fixed value at 0 -> 422.
  - Malformed expiry -> 422.
  - Fixed and capped coupons.
  - Expired and inactive coupons.
  - Preview totals and shipping threshold.
  - Coupon does not discount shipping.
  - Order placement with coupon.
  - Coupon snapshot when the coupon is deleted.
  - Admin order detail snapshot after deletion.
- Migration 021:
  - Pending on a pre-021 scratch schema.
  - Applies cleanly.
  - Adds three columns.
  - Adds the index.
  - Adds `ON DELETE SET NULL`.
  - Preserves the snapshot on coupon deletion.
  - Idempotent re-run.
- Admin accounts:
  - List.
  - Create.
  - Duplicate/validation errors.
  - Update.
  - Self-delete rejection.
  - Delete another admin.
  - Deleted account's token is rejected.
- Contact honeypot:
  - Filled honeypot -> 422.
  - No message stored.
  - Valid submission stores exactly one message.
- Backup script:
  - Creates a gzip dump.
  - Contains the orders table.
  - Prunes an old generated dump.
  - Preserves a manual file.
  - Leaves no `.tmp`.
  - Fails non-zero when `mysqldump` is unavailable.
  - Never prints the database password.
- Existing regression areas: auth, cart, checkout, payment callbacks, inventory, newsletter, error-response safety.

### Test evidence reconciliation

- Final retained API total: **324 passed / 0 failed**.
- Earlier retained total: **315 passed / 2 failed**.
- The final run happened about eight minutes after the failed run. The final run's higher total is consistent with additional tests being included; the two totals must not be treated as a simple retry of the same test set.
- No current API test failure is known from the retained artifacts.
- The final run's raw bodies are not available by design, so individual request/response payloads cannot be re-inspected from the report artifact.

### Unverified areas

- No **current** rerun of the API suite. The 324/324 result is prior evidence because the report task prohibited DB-mutating tests.
- No **current** unit test run and no retained unit-test artifact for the implementation changes.
- No `npm run build`; only type checking and linting were run.
- No browser E2E, manual click-through, responsive check, or screenshot review.
- No automated accessibility audit.
- No live SMTP/Resend delivery test. The API tests deliberately disable outbound email.
- No live card-gateway or payment-provider test.
- No migration 021 application to the real main database or a full clone of its data.
- No backup scheduler installation or restore drill.
- No admin last-deletion concurrency test; the guard was reviewed in source.
- No verification of coupon validation under concurrent admin edits beyond the row-lock design and migration/order tests.

### Environment

| Component | Version / value |
|---|---|
| OS | Windows (`win32`) |
| PHP | 8.5.1 ZTS, Visual C++ 2022 x64 |
| MySQL | 8.0.33 |
| Node.js | 24.14.0 |
| npm | 11.9.0 |
| Frontend framework | Next.js 16.3.1 |
| React | 19.2.8 |
| Test database in final API artifact | `lucebianca_test` |

---

## 8. Bugs, Gaps, and Risks

### P0 - release blocker

**1. Migration 021 is not applied to the main database while the new code expects it.**

- Evidence: read-only schema inspection shows 20 `schema_migrations` rows, no `021_add_orders_coupon_fields.sql` record, and zero coupon columns on `orders`.
- Source evidence: `OrderRepository::insertOrder` writes `coupon_id`, `coupon_code`, and `discount_amount`; admin order list queries explicitly select `coupon_code` and `discount_amount`.
- Impact (inferred, not executed against main DB): order placement and admin order queries would fail with unknown-column errors until the migration is applied.
- Required action: backup, staging-clone migration test, then production migration under a write freeze before deploying the new code.

### P1 - functional or operational gaps

**2. No hero video asset or approved poster exists.**

- `frontend/public/` contains `logo.png` and starter SVGs, not a hero video.
- The integration and fallback are implemented, but the visual requirement is incomplete until an asset is supplied and tested.
- No browser verification of autoplay, poster, or reduced-motion behavior.

**3. Backup scheduling is not installed.**

- The script and runbook exist; no cron/Task Scheduler entry ships with the repository.
- A backup that has never been restored is unverified. No restore drill was performed.
- The runbook's cron example redirects to `storage/logs/backup.log` from the repository root, but that directory does not exist; the actual log directory is `api/storage/logs/`. The example as written can fail to launch because the shell cannot open the redirect. This is a documentation defect, not a script defect.

**4. UI and visual behavior are not browser-verified.**

- Search overlay, coupon apply/error states, admin coupon forms, accounts tab, cookie notice, and hero fallback have no browser evidence.
- Responsive behavior and focus/keyboard behavior are unverified.
- The repository has no automated browser E2E configuration; `api/tests/README.md` documents this limitation.

### P2 - security, correctness, and documentation risks

**5. The public coupon preview has no dedicated rate limit.**

- Route: `POST /api/coupons/validate`, CSRF-protected but not rate-limited.
- A caller can probe codes repeatedly; responses reveal valid/invalid status.
- The endpoint also performs cart/stock work per request.
- Recommendation: add a modest per-IP/user rate limit or generic failure delay; do not weaken the current generic error messages.

**6. Admin password changes do not revoke existing tokens.**

- Access JWTs remain valid until expiry.
- Refresh tokens in `admin_refresh_tokens` are not cleared when a password is changed; deletion does cascade them.
- Impact: after a suspected password compromise, old sessions can persist.
- Recommendation: revoke refresh tokens and use a token-version/issued-at check on password change, or shorten access-token TTL and require re-login.

**7. Last-admin deletion guard is not independently exercised.**

- The self-delete guard runs before the last-admin check, so in normal operation the last remaining admin is also the actor and is already blocked by self-delete.
- The additional `count($ids) <= 1` guard is defense in depth and was source-reviewed, but no test isolates it.
- Concurrent deletion is guarded by a table-wide row lock; no concurrency stress test was run.

**8. Invalid calendar dates can normalize instead of being rejected.**

- `CouponsController::parseExpiresAt()` checks `createFromFormat()` for `false` but does not inspect `getLastErrors()`.
- A direct PHP check confirmed `2026-02-30 10:00` becomes `2026-03-02 10:00` with a warning, not a failure.
- The admin datetime-local control normally prevents this; a direct API call can still submit it.
- Recommendation: reject any parse result with warnings or errors, and add a test.

**9. The mobile cookie banner may cover the floating WhatsApp button.**

- Both use `bottom-4`; the banner spans nearly the full mobile width with `z-50`, and it is rendered after the WhatsApp button in `storefront-shell.tsx`.
- On small screens the banner can sit above the WhatsApp control until dismissed.
- This is source-inferred and not browser-confirmed.
- Recommendation: offset the banner above the button on mobile or move the button while the notice is visible.

**10. Test documentation is stale.**

- `api/tests/README.md` still describes the snapshot as 19 tables with migration coverage `001...015` and does not list the new search/coupon/account/backup suites.
- The snapshot still has 19 tables, so the table count is not wrong, but the migration-range wording is stale.
- The runbook's initial-schema reference says "14 tables"; the current schema has 19.
- Recommendation: update the test README and runbook after the implementation is committed.

**11. `frontend/.env.example` is untracked until deliberately added.**

- It is correctly unignored by the updated `.gitignore`, but it will not exist in a commit unless added.
- The hero variables and public API URL documentation could be lost during a careless commit.
- Recommendation: include it in the commit plan and review that the values are local placeholders with no secrets.

### P3 - accepted scope limits and residual risks

**12. No coupon minimum-spend, usage-limit, per-customer, or stacking support.**

- The coupons table has no columns for these rules.
- Adding them would require schema, API, UI, and order-validation work beyond the approved scope.

**13. No admin roles, super-admin, or deactivation.**

- The current auth model treats every admin as an equal operator.
- Deactivation would require schema and auth changes; current removal is deletion with guards.

**14. No multi-region shipping.**

- Current shipping is flat cost plus free-shipping threshold.
- Regional rates require business data and a schema/API/UI design.

**15. No abandoned-cart follow-up.**

- Requires guest cart persistence, tracking, email scheduling, and consent considerations.

**16. No logo upload workflow.**

- The storefront uses the committed logo asset; changing it is a deployment/asset decision, not part of this implementation.
- Storage, consumption, and visual-identity decisions remain open.

**17. Test/QA coverage has no frontend unit or component tests.**

- Type checking and linting do not prove rendered behavior.
- Browser QA is the main remaining confidence gap.

**18. `lucebianca_test_e2e` remains on the MySQL server.**

- It is a separate pre-existing database and was not touched.
- Its contents and purpose should be confirmed by the next maintainer before cleanup; this report does not authorize dropping it.

### Non-defects and intentional deviations

- The contact honeypot returns 422 rather than 200. This is intentional anti-bot opacity and is covered by tests.
- Coupons do not reduce shipping. This is the documented policy; the threshold uses the pre-discount subtotal.
- Coupon deletion sets the order link to NULL rather than blocking deletion. The order snapshot is the historical record and is tested.
- Admin account deletion returns 409 for a missing target rather than 404. This is a response-code consistency issue; the UI displays the returned message and no data-loss behavior was found.

---

## 9. Roadmap Status

| Roadmap task | Status | Evidence / remaining work |
|---|---|---|
| 1.1 Live header search | Complete in source; API behavior prior-tested | Search fields, debounce, results, shop continuation. Browser QA still needed. |
| 1.2 Contact form honeypot | Complete; already existed before this work | Regression tests added; 422/no-store behavior retained. |
| 1.3 Hero video and fallback | Partial; asset blocked | Video/poster/reduced-motion/error integration present. No approved asset; no browser check. |
| 1.4 Cookie consent banner | Complete in source | localStorage acknowledgement, privacy link, storefront-only mount. Visual/mobile check pending. |
| 2.1 Coupon preview and application API | Complete in source; prior API-tested | Public preview, order revalidation, row lock, totals policy. Main DB migration pending. |
| 2.2 Checkout coupon input | Complete in source | Apply/remove, preview totals, discount display, WhatsApp line. No browser check. |
| 2.3 Admin coupon management | Complete in source; prior API-tested | `/admin/coupons`, CRUD, activation, expiry, validation. No browser check. |
| 3.1 Automated daily DB backup | Partial; script complete, scheduler not installed | Script tested through the prior API suite. Cron/Task Scheduler and restore drill remain. |
| 3.2 Admin account management | Partial vs roadmap wording | List/create/update/delete and guards implemented. No super-admin or deactivation because schema/auth do not support them. |
| Multi-region shipping | Not started | Blocked on region/rate business data. |
| Abandoned cart follow-up | Not started | Optional; requires tracking and email scheduling. |
| Logo upload | Not started / blocked | Storage, consumption, and visual-identity decisions remain open. |

### Overall roadmap reading

The high-priority customer-facing and promotional items are implemented in source. The remaining items are operational, asset-dependent, or dependent on schema/business decisions. No new completion percentage is claimed because the source is uncommitted, the main database is not migrated, and browser QA is incomplete.

---

## 10. Project State and Handover

### Git state

- Branch: `main`.
- HEAD: `bc6130e28ef33e8756de73d728ae9f133fd74e02`.
- All implementation changes are uncommitted.
- No commit or push was performed.
- 29 tracked files are modified; 13 implementation files are untracked.
- `comprehensive_audit_and_roadmap.md` is an additional pre-existing untracked document.
- `docs/implementation-report-2026-10-18.md` is the only file created during report authoring.

### Database state

- Main configured database: `lucebianca`.
- 19 tables.
- 20 applied migrations recorded in `schema_migrations`.
- Migration 021 is pending and its columns are absent.
- Do not deploy the new code to this database before applying 021.
- The final API suite used `lucebianca_test` and dropped it.
- A separate `lucebianca_test_e2e` database exists from earlier work; it was not assessed or changed.

### Test artifacts

- `api/storage/test-reports/latest-report.md` / `.json`: final 324/324.
- `api/storage/test-reports/api-20261018-210227Z.md` / `.json`: earlier 315/317 with two snapshot failures.
- These are Git-ignored and should not be committed.
- Raw request/response bodies are intentionally not retained.

### Backups

- `backups/` is Git-ignored.
- Two pre-existing manually named dumps are present and untouched.
- No new backup was generated by the implementation or this report.
- Treat all dumps as confidential because they may contain customer PII.

### Configuration inputs

- `api/.env.example` documents `MYSQLDUMP_BIN`, `DB_BACKUP_DIR`, and `DB_BACKUP_RETENTION_DAYS`.
- `frontend/.env.example` documents `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_GOOGLE_CLIENT_ID`, `NEXT_PUBLIC_HERO_VIDEO_URL`, and `NEXT_PUBLIC_HERO_VIDEO_POSTER`.
- The examples contain local placeholder URLs and empty optional values; no secrets are included.
- Production must set the real values on the host, not commit them.

### Operational sequence for the next maintainer

1. Review the complete working-tree diff and decide what to commit. Do not commit generated test reports, runtime logs, backups, or the audit document by accident.
2. Take a fresh, restorable database backup and verify it.
3. Clone or stage the current database, apply migration 021 with the migration runner, and verify columns, index, FK, and existing order readability.
4. Run the API suite against the migrated staging schema, then run `npm run build`.
5. Perform browser QA on staging: search, coupon apply/remove/error, checkout, confirmation, admin coupons, admin accounts, cookie notice, hero fallback, mobile widths.
6. Apply migration 021 to production under a write freeze, verify, then deploy the new code.
7. Configure the hero asset/poster and backup schedule. Run a restore drill before relying on the backup.
8. Address the P2 items in Section 8, especially coupon endpoint rate limiting and token revocation on password change.

### Important operator notes

- The first admin must be seeded with `api/scripts/create-admin.php`; there is no public admin registration.
- Deleting an admin invalidates access tokens immediately through the live existence check, but password changes do not revoke existing tokens.
- Coupon codes are case-insensitive in the database; admin-created codes are uppercased.
- Coupon shipping thresholds use the pre-discount subtotal.
- Deleting a coupon intentionally preserves order code/discount snapshots.
- The cookie notice uses localStorage, not a server-side consent record.
- The hero video is opt-in via environment variables; empty values preserve the existing CSS/logo hero.
- The backup runbook's cron log path needs correction before use.

---

## 11. Prioritized Next Steps

### P0 - before any deployment

1. **Protect the database.** Take and verify a fresh backup of the target database. Do not apply migration 021 to production without a restorable backup.
2. **Migrate a staging clone first.** Run `php api/scripts/migrate.php --dry-run`, review the pending 021 statement, apply it to a clone of the current database, and verify the three columns, `idx_orders_coupon`, `fk_orders_coupon`, and existing order readability.
3. **Do not deploy the working tree against the current main database.** The source expects 021; the current database does not have it.
4. **Apply production migration under a write freeze**, then verify before routing traffic to the new code.

### P1 - complete the feature and operational baseline

5. **Run the frontend production build** with `npm run build` and fix any build-only failures.
6. **Browser QA** on staging:
   - Desktop and mobile search.
   - Coupon apply, invalid code, expired code, remove, and totals.
   - Checkout and order confirmation.
   - Admin coupon CRUD.
   - Admin account create/edit/delete and self-delete guard.
   - Cookie notice acceptance and persistence.
   - Hero fallback with and without video/poster.
   - Reduced-motion behavior.
7. **Supply and approve the hero video/poster**, then test autoplay, error fallback, and reduced motion.
8. **Install the backup schedule**, fix the runbook log path to `api/storage/logs/backup.log` (or create the documented directory), and perform a full restore drill.
9. **Rerun the API suite and unit suite** against the migrated schema and record the results. The report's 324/324 is prior evidence, not a post-migration rerun.

### P2 - hardening and documentation

10. Add a dedicated rate limit to `POST /api/coupons/validate` and keep generic error responses.
11. Revoke refresh tokens and invalidate access tokens on admin password change, or document a deliberate session policy.
12. Reject date parses with warnings in `CouponsController::parseExpiresAt()` and add a boundary test.
13. Adjust the mobile cookie notice so it does not cover the WhatsApp button.
14. Update `api/tests/README.md` for the current migration range and new suites; update the runbook's stale table count.
15. Add an explicit last-admin/concurrent-deletion test if the guard is to be considered independently verified.
16. Ensure `frontend/.env.example` is included in the eventual commit and contains no real secrets.

### P3 - product backlog requiring decisions

17. Multi-region shipping: obtain region/rate data, design the schema/API/UI, then implement.
18. Abandoned cart: decide on guest persistence, email scheduling, consent, and unsubscribe behavior before implementation.
19. Admin roles/super-admin/deactivation: decide whether the current equal-operator model is sufficient; otherwise design a schema and auth migration.
20. Coupon usage limits, minimum spend, and per-customer limits: add only if the business requires them and the schema is extended deliberately.
21. Logo upload and visual identity: resolve storage and asset-consumption decisions before building an admin workflow.
22. Review and either commit or intentionally retain the pre-existing untracked audit document; do not mix it into an implementation commit accidentally.
