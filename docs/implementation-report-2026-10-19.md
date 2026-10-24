# Implementation report — 2026-10-19

Continuation of the Luce Bianca audit/implementation work. This report is
separate from, and does not modify, `docs/implementation-report-2026-10-18.md`.

## 1. Scope and source of truth

The current working tree and Git diff were treated as authoritative. The work
focused on confirmed remaining defects only:

1. Rate limiting for the public coupon preview endpoint.
2. Admin session invalidation after password changes.
3. Strict rejection of impossible calendar dates for coupon expiry.
4. Mobile overlap between the cookie banner and WhatsApp button.
5. Outdated test, backup and migration documentation.
6. Focused regression tests for the changed behavior.

No commits or pushes were made. The pre-existing dirty working tree and
uncommitted feature work were preserved.

## 2. Migration 021 verification (read-only)

The configured database was verified before database-dependent work and again
afterward. No migration was re-run and no business records were changed.

| Check | Result |
| --- | --- |
| Database | `lucebianca` |
| Server | MySQL 8.0.33 |
| Tables | 19 |
| Migration history | 21 rows, `001_initial_schema.sql` through `021_add_orders_coupon_fields.sql` |
| 021 recorded | Yes, exactly once |
| `orders.coupon_id`, `orders.coupon_code`, `orders.discount_amount` | All present |
| `idx_orders_coupon` | Present |
| `fk_orders_coupon` | Present with `ON DELETE SET NULL` |
| Existing order rows | 6, readable |
| Orders whose `coupon_id` points at a missing coupon | 0 |

A pre-existing `lucebianca_test_e2e` database was observed in the server's
schema list and left untouched. The integration runner's own
`lucebianca_test` database was dropped after the successful run.

## 3. Implemented fixes

### 3.1 Coupon preview rate limiting

`POST /api/coupons/validate` now uses the existing `RateLimitMiddleware`
architecture:

- Key/bucket: `coupon_validate`.
- Budget: 20 attempts per 300 seconds per client IP.
- Applied after CSRF middleware, so CSRF and input validation remain intact.
- Exhausted budget returns HTTP 429 with `Retry-After` and
  `{"error":"Too many requests. Please try again later.","retry_after":…}`.

Changed file: `api/routes.php`.

### 3.2 Admin password changes invalidate sessions

Admin access JWTs are now bound to a server-side refresh-token session:

- `Auth::issue()` accepts an optional session id and emits it as the `sid`
  claim.
- `AdminAuthService` creates the `admin_refresh_tokens` row first and embeds
  that row id in the access token.
- `AuthMiddleware` requires an active, unexpired, non-revoked session row that
  belongs to the token's admin (`t.id = sid`, `t.admin_id = sub`,
  `revoked_at IS NULL`, `expires_at > NOW()`).
- `AdminRepository::update()` performs the password update and revocation of
  every admin refresh-token row (`revoked_at = NOW()`) in one transaction.
- Deleting an admin still invalidates access immediately because the session
  rows are removed by the existing foreign-key cascade and the middleware
  requires the live join.

No schema migration was required. Already-issued admin access tokens from
before this change have no `sid` claim and are rejected once; the next login
issues a bound token.

Changed files:

- `api/src/Core/Auth.php`
- `api/src/Services/AdminAuthService.php`
- `api/src/Core/AuthMiddleware.php`
- `api/src/Repositories/AdminRepository.php`
- `api/src/Controllers/Admin/AccountsController.php` (comments aligned with
  the new session behavior)

### 3.3 Strict coupon expiry dates

`CouponsController::parseExpiresAt()` now checks
`DateTimeImmutable::getLastErrors()` after parsing and rejects any warning or
error. PHP no longer silently normalizes impossible dates such as
`2026-02-30` into a different expiry.

Malformed values still fail, valid leap-day dates are preserved exactly, future
timestamps are accepted, and once a timestamp moves into the past the public
preview rejects the coupon.

Changed file: `api/src/Controllers/Admin/CouponsController.php`.

### 3.4 Cookie banner / WhatsApp overlap

The cookie banner now uses `bottom-24` on mobile and `sm:bottom-6` on larger
screens. The WhatsApp button remains at `bottom-4`/`sm:bottom-6`.

At 390×844 the banner bottom is 748px and the WhatsApp button starts at 772px
(24px gap). At 360×640 the gap is also 24px. At tablet width the two remain
separated horizontally. The banner's accept/reload behavior still works.

Changed file: `frontend/src/components/cookie-banner.tsx`.

## 4. Tests added or strengthened

### `api/tests/run-api-tests.php`

- Schema guard: the isolated test schema must contain all three 021 columns,
  `idx_orders_coupon`, and the `ON DELETE SET NULL` FK before the server boots.
- Coupon create/update expiry validation:
  - valid leap day (`2028-02-29`) stored exactly;
  - impossible `2026-02-30` rejected with 422 and nothing stored;
  - future expiry boundary accepted and previewed;
  - past expiry boundary rejected.
- Coupon preview rate limit:
  - 20 requests allowed;
  - 21st request returns 429, `Retry-After`, and no field-level error details.
- Admin account sessions:
  - old access token returns 401 immediately after a password change;
  - old refresh-token row is no longer active (active count 0); the repository
    sets `revoked_at` in the same transaction;
  - old password returns 401;
  - new password logs in normally and can access account management.
- Last-admin deletion guard under concurrency:
  - isolated scratch database with two admins;
  - two child PHP processes run the real `AdminRepository::deleteGuarded()`
    concurrently and try to delete each other;
  - exactly one deletion succeeds, the other is rejected by the last-admin
    guard, and exactly one admin remains.
- Existing self-deletion guard remains covered.

### `api/tests/run-unit-tests.php`

- JWT `sid` claim is present when a session id is supplied.
- Tokens issued without a session id omit `sid`.

No test was weakened to obtain a pass. The concurrency test uses the repository
code directly because there is no public refresh endpoint and two simultaneous
HTTP deletes would require additional test-only routing.

## 5. Verification results

All commands were run from the repository with the existing dependencies.

| Check | Result |
| --- | --- |
| PHP syntax (`php -l`, all 98 non-vendor API PHP files) | 0 failures |
| Unit tests | 120/120 passed |
| API integration tests | 341/341 passed |
| `npx tsc --noEmit --incremental false` | Passed |
| `npm run lint` | Passed |
| `npm run build` | Passed, 36 pages generated |
| `git diff --check` | No whitespace errors (only CRLF advisories) |

The integration runner used the isolated `lucebianca_test` database, rebuilt
from `database/schema.sql`; it was dropped after the successful run. The
developer database was not mutated by these tests.

## 6. Browser QA

Browser automation was not available through the built-in browser tool, but the
existing `playwright-core` package and system Chrome were already installed, so
read-only browser checks were run against the existing local dev servers.

Results:

- Mobile 390×844: cookie banner and WhatsApp button do not overlap; 24px gap.
- Mobile 360×640: no overlap; 24px gap.
- Tablet 768×1024: no rectangle overlap (banner and button are horizontally
  separated).
- Cookie acceptance stores `lucebianca:cookie-consent:v1`; after reload the
  banner remains hidden.
- Desktop header search overlay opened, accepted input, and received an API
  response (no "Search is unavailable" state). The current data had no match
  for the test term.
- One pre-existing Cloudinary product image returned 404 on the homepage. This
  is a content/asset issue unrelated to the changed code and was not modified.

No login, checkout, coupon write, admin write, payment, or email flow was
exercised in the browser, because the available local servers point at the
developer database. Those flows remain covered by the isolated API tests.

## 7. Documentation corrections

- `api/tests/README.md`
  - 19-table current schema, migration 021 columns/index/FK and schema guard.
  - Migration replay wording corrected from `001…015` to `001…021`.
  - Current coverage list includes search, coupons, rate limiting, admin
    sessions, concurrent deletion, 021 apply/idempotence, and backup checks.
  - Browser E2E limitation clarified: no Playwright config or bundled
    browsers; ad-hoc checks may use an installed system browser.
- `docs/runbooks/backup-and-restore.md`
  - Current schema count corrected from 14 to 19 tables.
  - Log path corrected to `api/storage/logs/backup.log`, with log-directory
    creation in the cron example.
  - Windows Task Scheduler example documented, without installing a task.
  - Restore verification now includes `schema_migrations` and the three 021
    order columns.
- `database/migrations/README.md`
  - 015–021 statuses updated to reflect the verified current development
    database.
  - 021 documentation now states the verified columns, index, and
    `ON DELETE SET NULL` FK.
  - Added read-only verification queries for the 021 index and FK.
  - Added an explicit note that status is environment-specific and every
    deployment database must be checked independently.

The prior implementation report was not edited.

## 8. Limitations and intentionally unfinished work

- No production scheduler was installed. Cron/Task Scheduler examples are
  documentation only.
- No restore drill was performed. A drill requires a disposable restore target,
  and none was created during this pass.
- No hero video or poster asset was approved or added. The existing optional
  hero video integration and static fallback were preserved;
  `frontend/.env.example` documents `NEXT_PUBLIC_HERO_VIDEO_URL` and
  `NEXT_PUBLIC_HERO_VIDEO_POSTER`.
- Admin refresh tokens have no public refresh endpoint in the current project
  phase. The old refresh token is proven unusable by checking that its row is
  revoked (the same lookup refresh would use).
- Pre-deploy admin access tokens without `sid` stop working and require one
  re-login. This is intentional.
- Browser QA did not mutate the developer database. Full browser E2E against an
  isolated database remains a future improvement.
- The pre-existing Cloudinary image 404 remains a content asset issue.

## 9. Final repository state

- Branch: `main`
- HEAD: `bc6130e`
- The working tree remains dirty with the prior uncommitted implementation plus
  these continuation changes.
- No commit, push, dependency installation, migration execution against the
  developer database, or production scheduler installation was performed.
- The prior report `docs/implementation-report-2026-10-18.md` remains intact.
