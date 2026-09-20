# 2026-09-24 — Customer and admin authentication audit

**Date:** 2026-09-24
**Mode:** read-only audit + minimal security fixes — no architecture change, no token invalidation unless secret was weak/default, no real email/send.

## 1) Model

**JWT (stateless, HS256):** `api/src/Core/Auth.php:14` `Auth::issue(sub, role, ttl)` builds `header.{alg:HS256,typ:JWT}.payload{sub,role,iat,exp}.signature` where `signature = HMAC-SHA256(header.payload, JWT_SECRET)` base64url. `verify()` checks header `alg===HS256 && typ===JWT`, `hash_equals` signature (constant-time), then claims: `sub` numeric `>0`, `role in [user,admin]`, `iat` numeric not future (+60s skew), `exp` numeric `>iat` and `>now`. TTLs from `.env`: `JWT_TTL_SECONDS` (access, default 3600) and `JWT_REFRESH_TTL_SECONDS` (refresh, default 604800) via `AuthService.php:354` / `AdminAuthService.php:87`.

**Refresh tokens (stateful, hashed):** raw `base64url(random_bytes(48))` (≈384-bit) returned once, only `SHA256(token)` stored (`RefreshTokenRepository.php:36` `hash('sha256',token)`). Customer table `refresh_tokens` (`user_id` FK, `token_hash` UNIQUE, `expires_at`, `revoked_at`), admin `admin_refresh_tokens` (`admin_id` FK) — completely separate tables/columns (`AdminRefreshTokenRepository.php:14` overrides `table/idColumn`), no cross-use. Rotation: `AuthService.php:301` `revoke(oldHash)` then `issueTokenPair` (new JWT+refresh); on `issue` failure restores old row so client can retry. Logout `AuthService.php:333` `revoke(hash)` idempotent (unknown already-revoked → `UPDATE … WHERE hash` affects 0, still 204).

**Admin vs customer separation:** `localStorage` keys `lucebianca:customer:session:v1` (`customer-auth.ts:33`) vs `lucebianca:admin:session:v1` (`admin.ts:26`) + `lb_admin_session` cookie (`admin.ts:60`) vs cart `lucebianca:cart:v1` — never shared. JWT `role` claim enforces separation: `AuthMiddleware.php:28` `new AuthMiddleware('user')` for `/api/account/*`, `new AuthMiddleware('admin')` for `/api/admin/*` (`routes.php:107,118`). `proxy.ts:31` gates `/admin/*` on cookie presence only (UX), real auth is API `AuthMiddleware` (signature+role).

## 2) What was fixed (confirmed issues only)

### JWT_SECRET fail-closed (`Auth.php:82`)

**Before:** `$secret = (string) Env::get('JWT_SECRET', 'insecure-default-secret')` — missing/empty `.env` silently signed with known default, attacker could forge any `user`/`admin` JWT.

**After:** `Auth.php:82` `getSecret()` → `Env::get('JWT_SECRET')` must be non-empty `string` trimmed, not in weak list (`insecure-default-secret`, `change-me`, `secret`, `changeme`) and `mb_strlen ≥32`; otherwise `throw RuntimeException('JWT_SECRET is not configured — failing closed.')` / `('…too weak — failing closed (need ≥32 random chars).')`. Both `sign()` and `verify()` call `getSecret()`, so `issue()` and `verify()` fail closed (500 before auth, `AuthMiddleware` catches as `401` with generic message, no secret in body). `CsrfProtection.php:94` already failed closed (`throw` if `JWT_SECRET===null`), now consistent.

**Impact on existing users:** tokens signed with the *same* production `JWT_SECRET` remain valid (signature still verifies, new claim checks pass because `issue()` always set `sub>0`, `role`, `iat`, `exp>i+60`). Only tokens signed with the old fallback `insecure-default-secret` or with `iat` in future / `exp ≤ iat` / missing `role` are now rejected — those were insecure and must be invalidated (re-login required).

### Token claims/role validation (`Auth.php:50`, `AuthMiddleware.php:28`)

**Before:** `verify()` checked only `hash_equals` + `exp < now`. A token with `alg:none`, `sub:"0"` or `sub:"abc"`, `role:""`, `iat` far future, or `exp==iat` would pass if signature (with default secret) was skipped.

**After:** `verify()` decodes header, rejects `alg !== HS256` or `typ !== JWT`; validates `sub` numeric `≥1`, `role ∈ [user,admin]`, `iat`/`exp` numeric, `iat ≤ now+60`, `exp > iat`, `exp ≥ now`. `AuthMiddleware.php:41` keeps `role` enforcement (`403` if `requiredRole !== claims.role`). `RequestContext` carries verified claims only.

### Admin gate cookie — production Secure (`admin.ts:60`, `config.ts`)

**Before:** `document.cookie = "...; path=/; SameSite=Lax"` — no `Secure`, so on `https` the JWT-bearing cookie could be sent over cleartext if ever downgraded; no `HttpOnly` note.

**After:** `admin.ts:60` `setSessionCookie` / `clearSessionCookie` append `; Secure` when `window.location.protocol === "https:"` (`frontend/src/lib/admin.ts:60`). `config.ts:ADMIN_COOKIE_NAME` centralized, comment documents that JS-set cookies cannot be `HttpOnly` — production should have PHP `Set-Cookie: lb_admin_session=…; HttpOnly; Secure; SameSite=Lax` from `/api/admin/auth/login` (deferred, not required for dev `http://localhost`). `proxy.ts:31` still presence-gated (real verification is `AuthMiddleware`).

### Rate limiting — admin login (`routes.php:53`)

**Before:** `POST /api/admin/auth/login` (`routes.php:123`) had **no** `RateLimitMiddleware` (customer `login` had `5/300s`, `register` `5/300s`, `contact` `3/600s`). Admin brute-force was uncapped.

**After:** `routes.php:53` `$adminLoginRateLimit = new RateLimitMiddleware('admin_login',5,300)` and `routes.php:123` `post('/api/admin/auth/login', …, [$adminLoginRateLimit])` → `429` + `Retry-After` on excess, same file-bucket isolation as customer.

### Logs — no secrets/tokens (`ErrorLogger.php:58`)

Verified: `ErrorLogger:124` redacts `Bearer\s+\S+` and `password|passwd|token|api[_-]?key|secret\s*[:=]\s*\S+` → `[redacted]`, caps at 500 chars, never logs bodies/headers/DB rows. `AuthService::issueTokenPair` logs not at all; `refresh`/`logout` only hash. `AuthMiddleware` errors are `401` generic, not log-worthy. No `JWT_SECRET`, `RESEND_API_KEY`, refresh `token` values appear in `app-error.log`.

## 3) Verified still correct (no change needed)

- **Refresh rotation:** `AuthService.php:301` revoke-old then `issueTokenPair`; on `issue` failure restores old hash — atomic, single-use, hash-only storage. Customer `refresh_tokens` verified via `findActive` (`revoked_at IS NULL AND expires_at > NOW()`). Admin mirrors via `AdminRefreshTokenRepository` (same code path, separate table) but **no** `/api/admin/auth/refresh` route exists (`routes.php:100` only customer `…/auth/refresh`) — admin tokens are issued on login (`AdminAuthService:95`) and live until `accessTtl` (customer `handleExpiredAdminSession` clears on `401`); documented as deferred, not a silent break.
- **Logout:** `AuthService:333`/`AdminAuthService` (admin logout not exposed) — `revoke(hash)` idempotent, `ValidationException` on missing `refresh_token` → `422`, not `500`.
- **LocalStorage separation:** `lucebianca:admin:session:v1` vs `lucebianca:customer:session:v1` vs `lucebianca:cart:v1` — `grep` shows no shared read/write; `customer-auth.ts:180` `commitSession` and `admin.ts:77` `saveAdminSession` write distinct keys.
- **Role/subject:** `OrderController:83` `authenticatedCustomerId` checks `claims.role==='user' && sub`, `AccountController:82` trusts `RequestContext` after `AuthMiddleware('user')`, so `GET /api/account/orders` cannot be fetched with admin JWT.

## 4) Deployment requirements (production)

- **Env (VPS, gitignored `api/.env`):**
  ```
  APP_ENV=production
  APP_DEBUG=false
  JWT_SECRET=<≥32 random chars, e.g. `openssl rand -hex 32`>  # never "insecure-default-secret" or "change-me"
  JWT_TTL_SECONDS=3600
  JWT_REFRESH_TTL_SECONDS=604800
  CORS_ALLOWED_ORIGINS=https://lucebianca.co
  ```
  If `JWT_SECRET` missing/weak, `Auth::issue`/`verify` throw → `GET /api/account` etc. → `401` (fail-closed); fix by setting a strong secret and restarting php-fpm. Rotate secret only with maintenance window — all existing JWTs (customer+admin) will be invalidated until re-login (refresh tokens remain hashed but new JWTs will use new secret).

- **Admin cookie (production `https`):** frontend `admin.ts` now adds `Secure`; for `HttpOnly` add `Set-Cookie: lb_admin_session=<token>; Path=/; HttpOnly; Secure; SameSite=Lax` from `POST /api/admin/auth/login` response (not yet implemented — keep JS fallback for dev). Ensure `proxy.ts:31` `lb_admin_session` matches `config.ts:ADMIN_COOKIE_NAME`.

- **Rate-limit storage:** `api/storage/rate-limits/` must be writable (`mkdir 0755` in `RateLimiter.php:44`), not shared across deploys without `RATE_LIMIT_STORAGE_DIR` override; behind Cloudflare/nginx, `X-Forwarded-For` is trusted only if you control the proxy (`RateLimitMiddleware.php:51`).

- **Logs:** `api/storage/logs/app-error.log` (`ErrorLogger.php:40`) stays gitignored, `X-Request-Id` header correlates client `500` → server line; never contains `Authorization` or `Cookie`.

## 5) Static checks (no DB, no network)

- `php -l api/src/**/*.php` (excl. `vendor/storage`) → `PHP LINT DONE` (0 failures, `api/src/Core/Auth.php:1` new `getSecret` lints).
- `frontend: npm run lint` → `0 errors, 1 warning` (`checkout-form.tsx:203` `react-hooks/set-state-in-effect` from `setPaymentMethod` in `useEffect`, `eslint-disable` unused — pre-existing, not auth).
- `npx tsc --noEmit --skipLibCheck` → `0` errors.
- `grep -r JWT_SECRET` → only `Env::get('JWT_SECRET')` in `Auth.php:82`/`CsrfProtection.php:94` plus new `getSecret` weak-list, no hard-coded secret in repo; `grep -r "insecure-default"` → only inside `Auth.php` weak-list, not as fallback.
- `grep -r ADMIN_COOKIE_NAME` → `config.ts`, `admin.ts`, `proxy.ts` consistent.

## 6) Remaining / deferred (not changed)

- Admin `refresh`/`logout` endpoints not yet exposed (`AdminAuthService::refreshTokens` created but no `POST /api/admin/auth/refresh`); admin JWT is short-lived, refresh is manual re-login (documented, not a silent break).
- `GET /api/account/orders` rate limit not added (low risk, paginated `user_id` scoped).
- `HttpOnly` for `lb_admin_session` still JS-set; server `Set-Cookie` is next step (preserve dev `http://localhost` compatibility).
