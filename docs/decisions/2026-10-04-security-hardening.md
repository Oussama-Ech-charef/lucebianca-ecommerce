# Security: trusted proxies, CSRF fail-closed, sessions, reset, verification, newsletter (2026-10-04)

- **Rate limiting:** `X-Forwarded-For` is honored only when `REMOTE_ADDR` is in
  `TRUSTED_PROXIES` (comma-separated IPs/CIDRs, empty = never trust,
  fail-closed). Otherwise `REMOTE_ADDR` is the identifier (validated, else
  `0.0.0.0`). Prevents clients spoofing the bucket. Configure reverse-proxy
  IPs in production (`api/.env.example`).
- **CSRF:** signing fails closed when `JWT_SECRET` is absent, empty, weak
  (<32 chars) or a known placeholder (`Auth`-identical rules). `generateToken`
  throws (no tokens issued); `validateToken` returns false (403 via middleware).
- **Sessions:** refresh tokens are issued as HttpOnly/Secure/SameSite=Lax
  cookies (`lb_customer_refresh`, `lb_admin_refresh`) + legacy JSON field for
  backward compat. New clients store only the short-lived access JWT + profile
  in localStorage; refresh/logout prefer the cookie (`credentials:include`).
  Admin UI protection is real: proxy cookie-presence is UX-only, every
  `/api/admin/*` verifies the JWT (`AuthMiddleware('admin')`), and the shell
  re-verifies via `GET /api/admin/auth/me` (401/404 → clear + redirect).
- **Password reset:** `POST /api/auth/forgot-password` (rate-limited 5/15 min,
  anti-enumeration identical message, single-use 1 h SHA-256-hashed token) →
  email link `/reset-password?token=` → `POST /api/auth/reset-password`
  (min 8, single-use invalidation + revokes all refresh sessions). Migration
  `017`. Owner must configure `RESEND_*` + `SITE_URL` for email delivery.
- **Email verification:** enforced server-side for reviews (403 when
  unverified). Login/checkout stay unblocked (informational); Google accounts
  are verified at creation. Policy documented in code + `/account` banner.
- **Newsletter:** unsubscribe requires a signed HMAC token (email-bound,
  1 y TTL, fail-closed without secret). The token travels by welcome email
  (best-effort `sendNewsletterWelcome`, never blocks the subscribe) and is
  NOT returned in the subscribe response; `GET /api/newsletter/unsubscribe`
  only validates the link (read-only, scanner-safe), the state change is
  `POST`-only from the confirmation click, with an `unsubscribe_count`
  audit. Email alone (422/403) is insufficient.
