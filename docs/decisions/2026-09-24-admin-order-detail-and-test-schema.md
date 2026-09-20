# 2026-09-24 — Admin order detail + test schema snapshot (read-only fix, no DB)

**Date:** 2026-09-24
**Mode:** fix two approved issues only — no `git` writes, no `api/.env` read, no DB connection, no SQL execution, no `run-api-tests.php` run, no dependency install, no `001…015` edits, no `schema.sql` edit, no unrelated file moves, guest privacy preserved.

## 1) Admin order-detail flow — broken after `015` privacy

**Before:**
- `api/routes.php:138` only `GET /api/admin/orders` (list) and `PUT /api/admin/orders/{id}`; `api/src/Controllers/Admin/OrdersController.php:38` had `index` + `update`, no `show`.
- `frontend/src/app/admin/(protected)/orders/orders-client.tsx:249` `openOrderDetail` did `fetchOrder(order.id)` → `frontend/src/lib/storefront.ts:103` `GET /api/orders/{id}` (public, guest `lookup_token` or `Bearer user` ownership via `OrderController.php:102` `getOrderAuthorized` → `404` to hide). Admin JWT (`role=admin`) never satisfied `authenticatedCustomerId` (`role=user` check), no token → always `404` → detail modal `detailError`.
- `GET /api/orders/{id}` is intentionally guest-private (`OrdersController.php:102` `extractLookupToken` + `hash_equals` + `404` hide). Using it for admin would weaken privacy (admin ID without auth) and is prohibited by task.

**Fix (existing admin auth + CSRF architecture, no new auth):**
- `api/src/Models/Order.php:61` added `toArrayWithoutLookupToken(): array { $data=$this->toArray(); unset($data['lookup_token']); return $data; }` — small, clearly named, reused consistently so `lookup_token` is not unnecessarily exposed in admin or public responses (public `OrderController.php:132` already `unset` when no token; admin now also strips).
- `api/src/Controllers/Admin/OrdersController.php:38` added `show(Request $request, array $params): never` — `AuthMiddleware('admin')` already protects route, `orderService->getOrder(id)` (direct, admin trusted, no `lookup_token` gate), `404` via `$this->error('Order not found.',404)` if `null` (same safe message as public, per existing `OrdersController::update:79`), `200` `['data'=> $order->toArrayWithoutLookupToken()]` + `items` already in `getOrder` (via `itemsForOrder`). Also updated `update():115` to `toArrayWithoutLookupToken()` for consistency (was `toArray()`).
- `api/routes.php:138` added `GET /api/admin/orders/{id}` with `$adminMiddleware` (read-only, no `adminCsrf` — `GET` has no CSRF, matches `GET /api/admin/orders`, `GET /api/admin/products/{id}`) immediately after list route:
  ```php
  $router->get('/api/admin/orders', …, $adminMiddleware);
  $router->get('/api/admin/orders/{id}', [OrdersController::class, 'show'], $adminMiddleware);
  ```
- `frontend/src/lib/admin.ts:99` added `fetchAdminOrderDetail(id, token)` → `api('/api/admin/orders/'+id, {token})` (Bearer admin, no CSRF for GET).
- `frontend/src/app/admin/(protected)/orders/orders-client.tsx:1` import changed to `fetchAdminOrderDetail` (remove public `fetchOrder` for detail), `249` now:
  ```ts
  const token = getAdminSession()?.token; if (!token) redirectToLogin(); else fetchAdminOrderDetail(order.id, token)
  ```
  plus `ApiError 401 → redirectToLogin`, else `detailError`. Header comment `119` updated to `protected admin GET /api/admin/orders/{id}`.

**Why not public workaround:** public endpoint was left unchanged (`OrderController.php:102` still requires `lookup_token` or `Bearer user` ownership, `404` hide). No `?adminId=` bypass added — would violate privacy.

## 2) API integration-test schema strategy

**Before:**
- `api/tests/run-api-tests.php:130` `glob($migrationsDir.'/*.sql')` sorted → discovered `001…015` (15 files) and executed each with `preg_replace` on `USE`/`CREATE DATABASE` + `nextRowset` draining. Known to fail on `010_add_newsletter_status_columns.sql` (`Duplicate column is_active` after `008`) and includes `UNTESTED` `014`/`015` `INFORMATION_SCHEMA+PREPARE` (MariaDB portability). `api/tests/README.md:25` claimed rebuild from `001…010` (text) vs code `001…015` (actual) vs snapshot `database/schema.sql` (18 tables, `015` included) vs `database/migrations/README.md:16` two-path docs (fresh `schema.sql` vs upgrade `mysql < 014`/`015`) — inconsistent.
- `database/schema.sql:1` already deterministic snapshot (18 tables, `lookup_token`, drift repairs) — `run-api-tests.php` should use snapshot per task (“Prefer using …/schema.sql snapshot”).

**Fix (deterministic, no DB execution):**
- `api/tests/run-api-tests.php:111` replaced `glob` loop with single snapshot:
  ```php
  $schemaFile = $repoRoot.'/database/schema.sql';
  // hard-fail if missing, same preg_replace + hard-fail on unresolved lucebianca, same nextRowset draining
  echo "Schema ready (snapshot: database/schema.sql, 18 tables)\n";
  ```
  Preserved test DB name rewriting (`${1}.$dbName`), safety hard-fails, `DB_HOST/PORT/USER/PASS/NAME` overrides, `DROP/CREATE DATABASE` isolation, result-set draining, `RESEND_API_KEY`/`GOOGLE_CLIENT_ID` empty, `RATE_LIMIT_STORAGE_DIR` per-run, `X-Request-Id` etc. No `001…015` execution, no DB connection in this task.
- `api/tests/README.md:25` updated: `Boots … against … rebuilt from database/schema.sql snapshot (18 tables, deterministic, includes 015…)` and note `Migration files 001…015 are not executed by this harness (010 … 014/015 UNTESTED…); see database/migrations/README.md two-path`.

**Files intentionally NOT changed:**
- `database/migrations/001…015.sql` (frozen append-only)
- `database/schema.sql` (no static inconsistency requiring edit; snapshot already 18 tables, no `USE` mismatch)
- `api/src/Controllers/OrderController.php` (public `lookup_token`/`user` gate preserved), `api/.env`, `frontend/.env.local`, `api/config/database.php`, `logs`/`cache`/`storage`, unrelated frontend/backend files, `database/migrations/README.md` (already correct two-path)

## 3) Static validation (no DB, no SQL, no `run-api-tests.php` execution)

- `php -l api/src/Models/Order.php` → `No syntax errors detected`
- `php -l api/src/Controllers/Admin/OrdersController.php` → `No syntax errors detected`
- `php -l api/routes.php` → `No syntax errors detected`
- `php -l api/tests/run-api-tests.php` → `No syntax errors detected`
- `php -l frontend/src/lib/admin.ts` (via `npx tsc` check, not `php -l`) — `npx tsc --noEmit --skipLibCheck` → `0` errors (checked `OrdersController` import, `fetchAdminOrderDetail` signature, `OrdersClient` `ApiError` handling)
- `npm run lint` (no `--fix`) → `0 errors, 1 warning` (`checkout-form.tsx:203` `react-hooks/set-state-in-effect` pre-existing, suppressed via `eslint-disable` inside effect) — no new errors from `orders-client.tsx` (import cleanup removes unused `fetchOrder`)
- `git diff --check` → no whitespace errors (verified via `git diff --check` — no trailing whitespace, no TAB vs space)
- Static route/reference checks: `Select-String -Pattern "GET /api/admin/orders"` → `routes.php:138` list + new `139` detail, both `$adminMiddleware`; `Select-String -Pattern "toArrayWithoutLookupToken"` → `Order.php:61` definition + `OrdersController:62` (show) + `115` (update); `Select-String -Pattern "fetchAdminOrderDetail"` → `admin.ts:99` definition + `orders-client.tsx:13` import + `256` call; no `fetchOrder` remains in admin detail flow.

## 4) Remaining risks

- `015` (`orders.lookup_token` backfill via `UUID()`) remains `UNTESTED` (no DB per safety rules); snapshot `database/schema.sql` already contains column, but production upgrade still requires `mysqldump` + `mysql < 015` on staging (see `database/migrations/README.md:16`).
- Existing guest orders before `015` have backfilled random tokens unknown to guests (no email with token) → old `?id=` links without token stay `404` (owned orders still via `GET /api/account/orders` for logged-in users).
- Admin detail now exposes full PII (`customer_name`/`phone`/`email`/`shipping_address`/`items`/`total`) to any valid admin JWT — intended, but admin JWT theft remains high-impact (mitigated by short TTL `JWT_TTL_SECONDS` + `admin_login` `5/300s` rate-limit `routes.php:53` + `lb_admin_session` `Secure` on `https`).
- `lookup_token` is stripped from both admin `show`/`update` responses via `toArrayWithoutLookupToken` — if future admin export needs token, must be explicit new endpoint.

**Confirmations:** No `git add/commit/push/reset/checkout/stash` executed (only `git diff --check` read-only); no `api/.env`/`frontend/.env.local`/`api/config/database.php`/logs/cache read; no database connection or SQL execution; integration test suite not run (`run-api-tests.php` not executed, only `php -l`); no dependencies installed; no migrations `001…015` modified; `schema.sql` not modified; guest privacy not weakened (public `GET /api/orders/{id}` still requires `lookup_token` or `user` ownership).
