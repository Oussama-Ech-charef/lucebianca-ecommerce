# Final audit — 2026-09-24 (read-only, no code changes)

**Date:** 2026-09-24
**Scope:** read-only comparison of implementation vs `README.md:1`, `docs/README.md:1`, every `docs/decisions/*.md:1` (23 files, `2026-08-16` → `2026-09-24`), every `docs/runbooks/*.md:1` (3 files), `lucebianca-project-full.md:1` (638 lines, spec sections 1-12). No `git` writes, no DB commands, no destructive tests; only `php -l`, `npm run lint`, `npx tsc --noEmit --skipLibCheck`, `Select-String`/`Get-Content` read-only checks.

## 1) Architecture summary (as implemented)

- **Frontend:** Next.js 16 App Router (`frontend/src/app/layout.tsx:1` `metadataBase: new URL(SITE_URL)`), React 19.2, Tailwind 4, TypeScript 5, Framer Motion (`frontend/src/components/hero.tsx:1`), Swiper (`frontend/src/app/product/[slug]/gallery.tsx:1`), `next/image` (0 raw `<img>` per `2026-09-23-frontend-production-readiness`). Server-rendered for SEO (`frontend/src/app/sitemap.ts:8`, `robots.ts:1`, `layout.tsx:20` `title`/`description`/`openGraph`), ISR on `/product/[slug]` (`revalidate=3600`), mobile-first responsive.
- **Backend:** PHP 8.1+ OOP (`api/src/Core/`, `Controllers/`, `Models/`, `Repositories/`, `Services/`), PSR-4 `App\` (`api/composer.json:13`), **PDO exclusively** (`api/src/Core/Database.php:63` singleton, `PDO::ATTR_ERRMODE_EXCEPTION`, every `prepare`+`bindValue`), Composer manual fallback (`api/src/autoload.php:1`).
- **DB:** MySQL 8 / MariaDB 10.4+ (`database/schema.sql:10` `lucebianca` `utf8mb4_unicode_ci`), **InnoDB** (`ENGINE=InnoDB`), `DECIMAL(10,2)` for prices, explicit `fk_`/`uq_`/`idx_`/`chk_`, `ENUM` for `orders.status`/`payment_status`.
- **Connection:** `React/Next.js (Vercel) ↔ REST API (PHP `api/public/index.php:1` + `api/routes.php:1` `Router`) ↔ MySQL` (`api/bootstrap.php:1` CORS from `CORS_ALLOWED_ORIGINS`, `Vary: Origin`, `Access-Control-Max-Age:86400`).
- **Auth:** stateless `HS256` JWT (`api/src/Core/Auth.php:14` `getSecret()` fail-closed, `issue/verify` with `sub>0`, `role=user|admin`, `iat`/`exp` + header `alg` check), short-lived access (`JWT_TTL_SECONDS`) + hashed refresh tokens (`RefreshTokenRepository.php:36` `hash('sha256')`), `AuthMiddleware.php:28` role enforcement.

## 2) Implemented features (vs. `lucebianca-project-full.md:6` + `README.md:61`)

- **Storefront:** `/` hero (Framer Motion video→static fallback, `2026-09-23`), `/shop` filtered/paginated (`api/src/Repositories/ProductRepository.php:28` `paginate` with `is_active=1`, `category/size/color/price`), `/product/[slug]` (`findActiveBySlug` + `Variant` + Swiper gallery + JSON-LD `BreadcrumbList/Product/Offer`), `/cart` (quantity + `POST /api/cart` live validation), `/checkout` guest + auth (attribution to `orders.user_id`), `/order-confirmation` (`fetchOrder(id, token)`), `/account` (`GET /api/account*`), `/login`/`/register` + Google OAuth (`GoogleOAuthService.php:94`), `/contact` (`is_read`), `/about`/`/size-guide`/`/shipping`/`/returns`/`/terms`/`/privacy` (draft copy preserved, `TODO` in `config.ts`), sitemap/robots dynamic.
- **Admin:** `/admin/login` (offline `api/scripts/create-admin.php` only, no public register), `/admin/dashboard` charts (`DashboardController.php:1`), `/admin/products` CRUD + `is_active` toggle + variant/SKU/`uq_variant_combo` + Cloudinary multi-image (`ProductsController.php:606` `CloudinaryService`), `/admin/orders` status enum-validated (`OrdersController.php:96`), `/admin/customers` (paginated, `CustomersController.php:28`), `/admin/categories`/`sizes`/`colors` (unique slug/name), `/admin/reviews` (`is_approved` gate), `/admin/messages` (`is_read`), `/admin/settings` (14-key whitelist, see §5).
- **Extras:** Reviews/ratings (`chk_reviews_rating`), Wishlist (`uq_wishlist_user_product`), Newsletter (`is_active`/`unsubscribed_at`), `settings` (`getAll`/`setMultiple`), floating WhatsApp (`frontend/src/components/whatsapp-button.tsx:1` `#25D366`), payment `cod`/`whatsapp`/`card` (hard-coded enum + settings toggle), `contact` honeypot + rate-limit.

## 3) Known limitations (not changed in this step)

- **Shipping cost calculation:** `settings.shipping_cost`/`free_shipping_threshold` validated & exposed via `GET /api/settings/public:58` but **not added** to `OrderService` `total_amount` (still items-only); `frontend/src/app/shipping/page.tsx:14` shows `SHIPPING_FALLBACK` placeholders with disclosure.
- **Store/SEO info:** `store_name/email/phone/address`, `seo_title` not yet consumed by `EmailService` templates/`layout.tsx:20` (`title` stays “Casual Luxury Tees”, `OG_IMAGE` is logo).
- **Coupons:** `coupons` table exists (`schema.sql:168`) but no UI/API for `code`/`discount_type`/`expires_at` → intentionally excluded per `2026-09-22-admin-settings`.
- **Analytics/admin accounts:** `google_analytics/meta_pixel`, `admin` add/remove not in `14-key` whitelist — deferred.
- **Admin `HttpOnly` cookie:** `frontend/src/lib/admin.ts:60` sets `lb_admin_session` via JS (`SameSite=Lax` + `Secure` on `https:`); `HttpOnly` requires PHP `Set-Cookie` header (deferred, `proxy.ts:31` still presence-gated, real auth is `AuthMiddleware`).
- **Production DB unknown:** no live host inspected; migration replay on empty DB still unsupported (`010` duplicate, `013` `IF NOT EXISTS` — use snapshot).

## 4) Database migration status

**Snapshot:** `database/schema.sql:1` **18 tables** (was `14` in `README.md:39` and spec `4`): `users` (`email_verified`+`google_id`+tokens, `005`/`004`), `admins`, `categories`, `products` (`sale_price` `011`), `product_variants`, `product_images`, `orders` (`email` `009` `VARCHAR(255) NULL`, `city`/`postal_code` `013` `DEFAULT ''`, `lookup_token` `015` `VARCHAR(64) NOT NULL UNIQUE`), `order_items`, `coupons`, `contact_messages`, `reviews` (`idx_reviews_approved` optional), `wishlist`, `newsletter_subscribers` (`is_active`/`unsubscribed_at`), `settings`, `product_sizes`/`product_colors` (`012`), `refresh_tokens` (`002`), `admin_refresh_tokens` (`003`).

**Migrations:** `database/migrations/001_initial_schema.sql:1` frozen (14) → `002` `refresh_tokens`, `003` `admin_refresh_tokens`, `004` `users.google_id`, `005` `users` verification, `006` `reviews` (`IF NOT EXISTS`), `007` `wishlist`, `008` `newsletter` (`is_active`), `009` `orders.email 255 NULL`, `010` `newsletter` duplicate (`Duplicate column` on replay), `011` `sale_price`, `012` `product_sizes/colors`, `013` `city/postal` (`IF NOT EXISTS` MySQL 8.0.23+ only), `014` drift repairs (`INFORMATION_SCHEMA`+`PREPARE`, re-runnable, UNTESTED), `015` `orders.lookup_token` (backfill `UUID()` → `UNIQUE`/`NOT NULL`). Two-path docs: fresh `mysql < database/schema.sql` only; upgrade `mysqldump` + `mysql < 014` then `015` (`database/migrations/README.md:16`).

**Verification (read-only):** `Select-String -Pattern "CREATE TABLE"` → `schema.sql` 18, `001` 14; `Select-String -Path database/migrations/015* -Pattern lookup_token` → present.

## 5) Security findings resolved

| Area | Before | After | File:line |
|---|---|---|---|
| **JWT secret** | `Auth.php:84` `(string)Env::get('JWT_SECRET','insecure-default-secret')` → forged tokens | `Auth.php:82` `getSecret()` fails closed if missing/empty/weak (`<32` or `insecure-default-secret`/`change-me`) → `500` → `401` via `AuthMiddleware`, no downgrade | `api/src/Core/Auth.php:82` |
| **JWT claims** | `Auth::verify` checked only `hash_equals`+`exp` → `alg:none`, `sub=0` could pass | header `alg===HS256`+`typ===JWT`, `sub>0`, `role∈[user,admin]`, `iat`/`exp` numeric, `iat≤now+60`, `exp>iat`, `exp≥now` | `Auth.php:50` |
| **Rate limiting** | `routes.php:123` `POST /api/admin/auth/login` no limit | `routes.php:53` `$adminLoginRateLimit=5/300s` + `[$adminLoginRateLimit]` on admin login; customer `login`/`register`/`contact` already `5/300`/`3/600` | `api/routes.php:53` |
| **Admin cookie** | `admin.ts:60` `SameSite=Lax` no `Secure`/`HttpOnly` note | `admin.ts:60` appends `; Secure` on `https:` + `config.ts:ADMIN_COOKIE_NAME` centralized, `HttpOnly` documented as requiring PHP `Set-Cookie` | `frontend/src/lib/admin.ts:60` |
| **XSS/SQLi/Auth** | **PDO** everywhere (`Database.php:63`), `htmlspecialchars` in `EmailService`, `password_hash` + `JWT` refresh rotation (`AuthService.php:301` hash-only, single-use) | preserved; added `isPaymentMethodDisabled` via `settings` toggle | `api/src/Services/OrderService.php:456` |
| **Error disclosure** | `bootstrap.php:24` every `Throwable` → `500` with `exception/file/line` when debug | `bootstrap.php:23` maps `Validation→422`, `Conflict→409`, `InvalidCredentials→401`, `PDO 23000→409` generic (`A record already exists.`), others `500` + `ErrorLogger:58` redacted (`Bearer`/`password`/`token`) | `api/bootstrap.php:23` |
| **Model property** | `User.php:13` camel (`emailVerified`) vs `CustomersController:116` `->email_verified` → `Undefined property` → `500` via `set_error_handler` | `CustomersController.php:110` `->emailVerified`/`->createdAt`, `PaymentController.php:58` `->paymentStatus`/`->paymentMethod`/`->totalAmount` | `api/src/Controllers/Admin/CustomersController.php:110` |
| **Order/payment privacy** | `GET /api/orders/{id}` public enumerable, `payment/status` public, `initiate` without ownership, callback only `HASH` | `orders.lookup_token` `64hex` (`015`) → `OrderRepository:69` `findAuthorized` (`hash_equals`, `404` to hide existence), `OrderController:102`/`PaymentController:43` require `?token=`/`X-Order-Token` or `Bearer user` ownership, `checkout-form` `router.push?id=&token=`, `PaymentController:92` callback now `order existence`+`paymentMethod==='card'`+`amount == total_amount`+`pending→paid/failed` + idempotent replay via state | `api/src/Models/Order.php:19`, `database/migrations/015*` |
| **Admin settings** | `SettingsController:40` any key allowed → could store `cmi_store_key` → `GET` leaked | `SettingsController:18` 14-key whitelist (`store_*`, `whatsapp_number`, `shipping_*`, `payment_*_enabled`, `notify_*`) + `NEVER_EXPOSE_PATTERNS` (`secret|password|token|hash|cmi|jwt|key`), `422` on unknown/secret, `filterForExposure` for `GET`/`GET /api/settings/public:58` (`10` safe keys) | `api/src/Controllers/Admin/SettingsController.php:18` |
| **Email/notification** | `OrderService:346` `updateStatus` used only `user.email` → guest `shipping_status` never sent; `notifyLowStock` inside `transaction` → spurious on rollback; `ErrorLogger` already redacted | `OrderService:346` fallback `order.email ?? user.email`, `OrderService:232` `lowStockAlerts[]` after `commit`, `NotificationService:28` `isNotifyEnabled` + `OrderService:476`/`523` gates, `ErrorLogger:58` never logs bodies | `api/src/Services/OrderService.php:346` |
| **Frontend placeholders** | `WHATSAPP_NUMBER` hard-coded, `SOCIAL_LINKS` `#`, `SHIPPING` `25/45/200` + `SIZE_CHART` `94…` hard-coded, `NEXT_PUBLIC_SITE_URL` `localhost` fallback without warning, `lb_admin_session` no `Secure` | `frontend/src/lib/config.ts:1` centralizes all fallbacks + `TODO (launch)`, `site.ts:10`/`api.ts:4`/`admin.ts:26` import from `config`, `whatsapp.ts:11` re-exports `WHATSAPP_FALLBACK`, `footer.tsx:12` imports `SOCIAL_LINKS`, `shipping/page.tsx:14`/`size-guide/page.tsx:12` import fallbacks, `admin.ts:60` `Secure`, `layout` generic `metadata` kept but documented | `frontend/src/lib/config.ts:1` |

## 6) Remaining production blockers (do NOT invent values)

- **Env (VPS, gitignored `api/.env`):** `APP_ENV=production`, `APP_DEBUG=false`, `JWT_SECRET` `≥32` `openssl rand -hex 32` (rotate invalidates all JWTs—re-login required), `CORS_ALLOWED_ORIGINS=https://lucebianca.co`, `DB_*` (never `root` empty), `RESEND_API_KEY`+`RESEND_FROM_EMAIL` verified domain (free `onboarding@resend.dev` only to owner's test inbox), `CLOUDINARY_*` (fallback manual autoloader still works but `uploadImages` needs keys), `CMI_*` (`CMI_CLIENT_ID`/`STORE_KEY`/`HASH_KEY`/`GATEWAY_URL`/`CALLBACK_URL`) if `payment_card_enabled=1`, `ADMIN_EMAIL` or `store_email` for admin notifications, `SITE_URL=https://lucebianca.co` and `NEXT_PUBLIC_SITE_URL`/`NEXT_PUBLIC_API_URL` (`https://api.lucebianca.co`) for `metadataBase`/`sitemap`/`robots`/`OG`/`CORS` (see `2026-09-23-frontend-production-readiness`).
- **Business placeholders (owner must replace, no guessing):** `SOCIAL_LINKS` `#` → real `https://instagram.com/lucebianca.ma` etc. (`config.ts:18`), `SIZE_CHART` `94…134` → measured garment `chest/length/shoulder/sleeve` cm (`size-guide/page.tsx:12`), `SHIPPING` `25/45/200` + `500 MAD` threshold → admin `shipping_cost`/`free_shipping_threshold` + `supported regions` via `GET /api/settings/public` (currently fallback disclosure, not charged in `OrderService`), `about/returns/terms/privacy/shipping/size-guide` draft copy → legal review (Moroccan consumer law).
- **Admin cookie `HttpOnly`:** JS `document.cookie` cannot set `HttpOnly`; prod must have PHP `Set-Cookie: lb_admin_session=…; HttpOnly; Secure; SameSite=Lax` from `/api/admin/auth/login` (deferred, `admin.ts:60` JS fallback + `proxy.ts:31` presence gate + real `AuthMiddleware`).
- **DB:** `014`+`015` are `UNTESTED` (no DB per task); existing guest orders before `015` have backfilled random `lookup_token` unknown to guest (no email with token) → old `?id=` links without token now `404` (owned orders still via `GET /api/account/orders`).
- **Shipping cost not charged:** `shipping_cost` exposed but `OrderService` total stays items-only — adding shipping changes `total_amount` business rule (deferred).

## 7) Safe verification commands (read-only, non-destructive)

```bash
# PHP lint (excl. vendor/storage)
Get-ChildItem -Path api -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch "\\vendor\\" -and $_.FullName -notmatch "\\storage\\" } | ForEach-Object { php -l $_.FullName }
# Frontend lint + type (storefront design preserved)
cd frontend; npm run lint; npx tsc --noEmit --skipLibCheck
# Routes/migrations snapshot
Select-String -Path api/routes.php -Pattern "get\(|post\(|put\(|delete\(" | Measure-Object
Select-String -Path database/schema.sql -Pattern "CREATE TABLE" | Measure-Object # expect 18
Select-String -Path database/migrations/*.sql -Pattern "CREATE TABLE" | Measure-Object
# Secrets never in repo
Select-String -Path api/.env -Pattern "JWT_SECRET|RESEND_API_KEY"  # should be gitignored; do NOT cat .env
Select-String -Path api/src -Pattern "insecure-default-secret" # only in Auth.php weak-list, not as fallback
# Settings public never leaks secrets
curl -s http://127.0.0.1:8090/api/settings/public | jq 'has("CMI")|has("secret")' # → false
# Order privacy
# Place guest order, save lookup_token, then:
curl -i http://127.0.0.1:8090/api/orders/1 # 404 without token
curl -s "http://127.0.0.1:8090/api/orders/1?token=<64hex>" | jq .data.customer_name # 200 only with token
# Email/notification toggles
curl -s http://127.0.0.1:8090/api/health | jq .status # smoke test
Get-Content api/storage/logs/app-error.log -Tail 20 # check X-Request-Id correlation, no Bearer/token in message
```

No `mysql`/`mysqldump` executed here; for DB upgrades see `database/migrations/README.md:16` (`mysql < database/schema.sql` fresh only, else backup + `mysql < 014` then `015`).

## 8) Deployment checklist (exact, before `next build` / VPS)

- [ ] `frontend/.env.production` (Vercel): `NEXT_PUBLIC_SITE_URL=https://lucebianca.co`, `NEXT_PUBLIC_API_URL=https://api.lucebianca.co`, `NEXT_PUBLIC_GOOGLE_CLIENT_ID=…` if Google login enabled.
- [ ] `api/.env` (VPS, `600`): `APP_ENV=production`, `APP_DEBUG=false`, `JWT_SECRET` `≥32` random, `CORS_ALLOWED_ORIGINS=https://lucebianca.co`, `DB_*`, `RESEND_API_KEY`+`RESEND_FROM_EMAIL` verified domain, `CLOUDINARY_*`, `CMI_*` if card enabled, `ADMIN_EMAIL`, `SITE_URL`.
- [ ] `admin/settings` 14 keys: `store_*`, `whatsapp_number` (`+?[0-9]{8,20}`), `shipping_cost`/`free_shipping_threshold` (0-99999), `payment_*_enabled`/`notify_*` (`0`/`1`).
- [ ] Business placeholders: `SOCIAL_LINKS` (`config.ts`), `SIZE_CHART` measured, `SHIPPING` real regions, `about/returns/terms/privacy` legal review.
- [ ] `JWT_SECRET` rotation plan (invalidates JWTs), `HttpOnly` `Set-Cookie` for `lb_admin_session` (PHP), `api/storage/rate-limits/` writable, `storage/logs/` gitignored.
- [ ] `composer dump-autoload` (optional, fallback exists), `php -l`, `frontend: npm run build` (fails if `metadataBase: new URL(SITE_URL)` is invalid `SITE_URL`).
- [ ] Verify `curl -s https://lucebianca.co/api/settings/public | jq .data.whatsapp_number` (real, not `212612345678`), `curl https://lucebianca.co/api/health` `X-Request-Id`, `/shipping` & `/size-guide` disclosures gone, `/admin` gate `Secure` cookie on `https`.

## 9) Documentation accuracy (diff vs. source of truth)

- `README.md:1` was `14 tables`, `only storefront list/read`, `501` stubs — **updated** to `18 tables`, full `Public` + `Admin` route list (`api/routes.php:49`), `docs` index, accurate as of `2026-09-24`.
- `docs/README.md:1` was `analysis/` empty, listed through `2026-09-20` — **updated** to 23 decisions (`2026-09-24-auth-audit` latest) and `analysis/2026-09-24-final-audit.md` (this file) + 3 runbooks.
- `docs/decisions/*.md:1` (23) each match `api/src`/`frontend/src` implementation after fixes (e.g., `2026-09-19-schema-drift-audit` now 18 tables with `015`, `2026-09-22-admin-settings` 14-key whitelist matches `SettingsController:18`).
- `docs/runbooks/*.md:1` (`backup-and-restore`, `uptime-monitoring`, `https-and-security-headers`) still accurate; `https` runbook now flagged `lb_admin_session` lacks `Secure` — partially resolved in `admin.ts:60` (`Secure` on `https:`), `HttpOnly` still deferred (noted in §6).
- `lucebianca-project-full.md:1` is **spec** (remains `14` tables, `only list/read`, generic SEO placeholders) — implementation intentionally diverges for security (`lookup_token`, `fail-closed JWT`, `4xx` mapping) and is documented above; spec file itself not mutated per task (read-only).

**Lint:** `php -l` (70+ files excl. `vendor/storage`) `PHP LINT DONE` (0 failures); `frontend: npm run lint` `0 errors, 1 warning` (`checkout-form.tsx:203` `set-state-in-effect`, `eslint-disable` unused — pre-existing, design preserved); `npx tsc --noEmit --skipLibCheck` `0` errors.
