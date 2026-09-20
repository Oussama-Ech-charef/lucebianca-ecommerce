# Phase 4 — Admin auth (login) + product CRUD + multi-image Cloudinary upload

Date: 2026-08-17 (local-only notes, matches the docs convention so far)

Covers roadmap item 4: admin auth + product CRUD (with multi-image upload).

## A. Admin authentication (login only)

- New public route `POST /api/admin/auth/login` (the ONE public admin route —
  no middleware). **No public admin registration exists** (spec section 4
  security comment). The only way to create an admin is the offline CLI
  script `api/scripts/create-admin.php` (interactive prompts, or
  `php scripts/create-admin.php "Name" email pass`; rejects duplicates,
  min 8-char password, CLI-only guard).
- `App\Services\AdminAuthService` — deliberately separate from the customer
  `AuthService` (admins are a fully separate table; not blurred into one
  code path). `login()`: email in `admins` + `password_verify()`, JWT via
  `Auth::issue($adminId, 'admin', $ttl)` (role claim → passes
  `AuthMiddleware('admin')`), refresh token stored hashed. Same 401 generic
  message and 422 validation as customer login.
- `App\Controllers\Admin\AuthController::login()` (registered as
  `Admin\AuthController` — matches the codebase's Admin-namespace convention), mapped 422/401/200.

### refresh-token FK decision (part A requirement)

`refresh_tokens.user_id` is a FK **to `users(id)` only**; admin ids live in
the separate `admins` table. Reusing `refresh_tokens` for admins would either
hit the FK for ids absent from `users`, or silently bind an admin's token to
an unrelated customer when an id collides.

**Decision:** a dedicated `admin_refresh_tokens` table (migration
`003_add_admin_refresh_tokens_table.sql`, same shape/hash-only policy) FK'd to
`admins(id) ON DELETE CASCADE`. Customer and admin token stores stay fully
separate. The repository is shared via `AdminRefreshTokenRepository extends
RefreshTokenRepository` (overrides `$table`/`$idColumn`) — DRY, one code path
for create/findActive/revoke. No `/api/admin/auth/refresh|logout` endpoints
this phase (tokens are stored for a later phase to wire them).

## B. Product CRUD (admin)

`ProductController` (Admin) fully implemented (was 501 stubs):

- `index` — paginated list of ALL products (active AND paused). Reuses
  `ProductRepository::paginate(..., $activeOnly = false)`; same
  `{data, meta}` shape as the public endpoint, no `is_active` forced filter.
- `store` — required: `name`, `base_price`, `meta_title`, `meta_description`
  (SEO fields validate present per the spec's SEO requirement);
  `category_id` optional (+ existence check before the FK can reject);
  `is_active` optional (0/1), default `1`. **Slug decision:** a provided
  `slug` is honored (slugified); otherwise auto-generated from `name` via
  `App\Core\Slugger` (iconv `ASCII//TRANSLIT`, no mbstring dependency) with
  automatic `-2`, `-3`, … uniqueness suffixes. A *provided* slug that's taken
  → 409. Variants accepted as an array and validated per item (`size`,
  `color`, `sku` required; optional `price` / `stock_quantity`), with
  same-batch duplicate SKU and size/color-combo checks.
- **Variant transaction design:** product + variants insert inside one PDO
  transaction on the shared `Database::get()` connection. Any variant insert
  failure → full rollback; a `23000` integrity error (dup SKU / dup combo)
  → 409, anything else rethrown → 500. The response embeds the product plus
  its variants (`data.variants`).
- `update` — accepts a full or **partial** body (this is what the
  Activate/Deactivate toggle uses: a dedicated PATCH route was NOT added —
  `PUT` with `{"is_active":0}` covers it cleanly and works end-to-end, see
  verification). Variants are deliberately NOT touched here (adding/removing
  variants = later refinement, noted in the report).
- `destroy` — **RESTRICT vs 409:** `order_items.product_variant_id` is
  `ON DELETE RESTRICT`. Before hard-deleting we check
  `ProductVariantRepository::isOrdered($id)`; if any variant appears in
  `order_items` → 409 `{"error":"Product has been ordered and cannot be
  deleted; deactivate it instead."}`. Otherwise hard delete is safe and the
  schema cascades to `product_variants` / `product_images` (verified).
- New repos: `ProductVariantRepository` (create, listByProduct, isOrdered),
  `ProductImageRepository` (create, countByProduct, listByProduct, clearMain).

## C. Multi-image upload via Cloudinary

- `composer require cloudinary/cloudinary_php` → **3.1.3** installed
  (official SDK + transformation-builder + Guzzle). Installed with
  `--prefer-source` because the deploy PHP build has no `zip` extension.
- `App\Services\CloudinaryService` wraps the SDK, reads
  `CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET`
  from `.env` (all three present — verified without printing values; the
  service throws a clear error if any is missing). `upload()` returns the
  secure URL; `delete()` best-effort destructs by public id. Uses the vetted
  library instead of hand-rolled signed requests (spec 3.1).
- `POST /api/admin/products/{id}/images` accepts multipart `images[]`.
  `Request` gained a `files()` helper that normalizes `$_FILES` (multi +
  single field shapes) without touching the JSON-body `input()/all()` used by
  every other endpoint. Each file is validated as a REAL image by inspecting
  **content** via `getimagesize()` (`IMAGETYPE_*` → jpeg/png/webp/gif), not
  the extension or client MIME (spec 3.1 strict validation). Only the
  Cloudinary URL is ever stored in `product_images.image_url` (spec section
  4 — never a local path). Defaults: first upload of a
  product-with-no-images becomes `is_main=1`; `is_main` (index) / `sort_order[]`
  may override; a new main clears the old one so there's exactly one.
- `CloudinaryService` is created **lazily** inside `uploadImages()` so
  product CRUD keeps working even if Cloudinary is down/misconfigured.

## D. Other fixes made along the way

- `api/src/autoload.php` — Composer vendor path bug: it checked
  `src/vendor/autoload.php` (nonexistent) instead of `vendor/autoload.php`;
  fine for App\ classes (manual fallback), but it silently never loaded
  Composer packages (first surfaced with the SDK). Fixed the path.
- `Models\Variant` — `fromRow()` read a `created_at` column that
  `product_variants` doesn't have; now defaults to ''. Schema is unchanged.

## E. Verification evidence (real command output, abbreviated)

- `php -l` on every new/changed file → `0 failures`;
  `composer dump-autoload -o` → 939 classes (incl. SDK + deps).
- Migration 003 applied to the live `lucebianca` DB (table + `admins(id)` FK
  confirmed via `information_schema`).
- `create-admin.php` seeded admin id=1 (`admin.test@lucebianca.dev`); DB row
  hash prefix `$2y$`, length 60 → real bcrypt, never plaintext.
- Smoke tests (PHP built-in server, 127.0.0.1:8090):
  - admin login correct → **200** `{token, refresh_token, admin}`; wrong pw → **401**
  - store product + 2 variants → **201**; DB afterwards:
    `product:3:1`, `variant CLT-M-BLK NULL/25`, `variant CLT-L-BLK 44.99/10`
  - store incomplete body → **422** (meta fields required)
  - paused product (is_active=0) → appears in admin list but NOT in public
    `GET /api/products`
  - `PUT /api/admin/products/3 {"is_active":0}` → gone from public, still in
    admin list (toggle works via `update()`)
  - upload 2 PNGs → **201**, two `product_images` rows with real
    `https://res.cloudinary.com/...` URLs (is_main 1/0, sort 1/2); fetching
    the URL returned **200** `image/png` (image live, not just a DB row)
  - `DELETE` product with zero orders → **204**, cascade wiped its
    variants/images (product count back to 0)
  - product with a fake `order_items` row referencing one variant → `DELETE`
    → **409** with the deactivate-instead message; product still present
- Cloudinary cleanup: both uploaded test images `destroy()`ed and re-checked
  via the admin API → 404 (confirmed gone). Local DB cleaned (products,
  variants, images, fake order all 0).
- Test credentials: the seeded admin uses email `admin.test@lucebianca.dev`
  and password `Admin-Test-2026!` (clearly-marked throwaway test credential).
  Left in place so it can be tested; replace it by deleting the row via mysql
  and re-running `create-admin.php`, or a password-change feature can arrive
  in the settings phase.

## Security / secrecy confirmation

No Cloudinary secret and no admin password appeared in any log, terminal
output, or committed file during this phase: `.env` remains gitignored, the
services read credentials only from `Env`, smoke-test output printed JWTs and
URLs but never the API secret, and `create-admin.php` never echoes the
password or its hash. The only hash shown was the bcrypt `$2y$` DB prefix
(revoked nothing). `composer.lock`/`vendor/` stay gitignored.

## Remaining for later phases

- Admin `/api/admin/auth/refresh` + `/logout` (tokens already stored).
- Variant add/remove/edit via the admin API (update() deliberately leaves
  variants untouched).
- Rate limiting on login/register/contact + Google OAuth.