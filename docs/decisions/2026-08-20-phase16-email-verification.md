# Phase 16 — Email verification (Resend)

Date: 2026-08-20

Email verification for **password-registered** customers, delivered through
**Resend**'s REST API. Verification is informational by design — an account
works (login, checkout, order history) before its email is verified; the
storefront surfaces a resend banner instead. Google OAuth accounts are created
already verified (Google proves the address). Admin auth, payment and all
prior phases untouched.

## A. Backend

- **Migration 005** (`database/migrations/005_add_users_email_verification.sql`,
  append-only): `users.email_verified` `TINYINT(1) NOT NULL DEFAULT 0`,
  `email_verification_token VARCHAR(255) NULL` + unique key, and
  `email_verification_expires_at DATETIME NULL`. Applied via
  `Get-Content ... | mysql -u root`.
- **`App\Services\EmailService`** — plain-PHP HTTP wrapper around Resend
  `POST /emails` (stream_context + `file_get_contents`, same style as
  `GoogleOAuthService`; no SDK, no SMTP). `sendVerificationEmail(to, name,
  link)` renders a small inline-styled HTML template. Config in
  `api/.env`/`.env.example`: `RESEND_API_KEY`, `RESEND_FROM_EMAIL`
  (default `onboarding@resend.dev`), and `SITE_URL` (the backend builds the
  verification link as `{SITE_URL}/verify-email?token=…`, mirroring the
  frontend's `NEXT_PUBLIC_SITE_URL`).
- **`AuthService::register`** now generates a single-use token
  (`bin2hex(random_bytes(32))`, 24 h expiry), persists it, and emails the
  link. The send is **best-effort**: a failure (or unconfigured key) is
  `error_log`'d and registration still returns 201 — the account exists, just
  unverified, and the user can resend.
- **`AuthService::loginWithGoogle`** creates new accounts with
  `email_verified = 1` (no token) — Google has already verified the address.
- **`AuthService::verifyEmail`** — finds by token, rejects unknown/already-used
  tokens and expired links with clean messages, then `markEmailVerified`
  (sets 1 + clears token + expiry, so a link can never be replayed).
- **`AuthService::resendVerification`** — anti-enumeration: identical success
  whether the email is unknown, verified or unverified; only a real unverified
  account gets a fresh token (which overwrites the old one, killing stale
  links). Send is best-effort like registration.
- **Endpoints** (all public): `GET /api/auth/verify-email?token=…`
  (200/422/400) and `POST /api/auth/resend-verification` `{email}`
  (200/422). `GET /api/account` now returns `email_verified` on the user
  payload; login/checkout are never gated on it.

## B. Frontend

- **`/verify-email`** — server `page.tsx` wraps a client view in `<Suspense>`
  (Next 16 requires the boundary when a client component reads
  `useSearchParams`). Reads `?token=`, verifies once on mount, shows a green
  success box with account/shop links, or the API's clean error plus a resend
  form. **Gotcha:** the verification link is single-use, so the fetch must
  fire exactly once even under React StrictMode (Next dev double-mounts
  effects) — a `useRef` guard does this; the per-effect `cancelled` flag was
  dropped because StrictMode's cleanup ran it before the response arrived.
- **`/account`** — when `email_verified` is false, an amber banner shows the
  email with a **"Resend verification email"** button (success/failure
  feedback inline) and a **Dismiss** action persisted in `sessionStorage`
  (`lucebianca:verify-banner-dismissed:v1`) so a refresh doesn't reset it.
- **`/register`** — static note under the password field that a verification
  email will be sent; the existing redirect to `/account` is unchanged.
- Helpers `verifyCustomerEmail(token)` and
  `resendCustomerVerification(email)` in `customer-auth.ts`; `CustomerUser`
  gains `email_verified`.

## C. Verification

- `php -l` clean on all changed/new PHP files.
- `npm run lint` 0 errors; `npm run build` clean; `/verify-email` prerenders
  as `○ (Static)`.
- API smoke (live API): register → 201 with `email_verified:false` + token row
  (24 h expiry); verify with the real token → 200 and DB flips to 1 with the
  token cleared; reusing the token → 400 "invalid or already used"; bogus
  token → 400; missing token → 422; resend on unverified → 200 generic +
  token rotated; resend on unknown/verified → identical 200; invalid email →
  422. Full E2E: register as `delivered@resend.dev` → verify through the API →
  flag 1.
- **Real Resend integration proven**: `EmailService::sendVerificationEmail`
  and `::send` were executed directly against Resend with the configured key
  and returned 200 with an email id. The key is valid for sends.
- Playwright-core + system Edge @ 1280×900: **11/11 PASS** — valid-token
  success box + links; bogus-token error + resend form; register note; account
  banner appears, resend shows confirmation, dismiss hides it, and it stays
  hidden after reload (session flag).
- All phase-16 test users and their refresh tokens removed after verification
  (pre-existing users 10/11 untouched).

## Notes / follow-ups

- **Sender restriction (must fix before launch):** the provided key + shared
  `onboarding@resend.dev` sender only deliver to Resend's test inbox
  (`delivered@resend.dev`); Resend rejects sends to real customer addresses
  with a 422 until the `lucebianca.co` domain is added to the Resend account
  and a verified sender is used. Before launch: add the domain, set
  `RESEND_FROM_EMAIL` to a verified address, and set `SITE_URL` (api) /
  `NEXT_PUBLIC_SITE_URL` (frontend) to the production origin.
- The key returns 401 on Resend's `GET /emails` (list) endpoint — it only has
  send permission; irrelevant to the app.
- Verification is deliberately non-blocking; a future phase could require it
  for privileged actions if the spec changes.
- Anti-spam on `/api/contact` is still deferred to the security phase.