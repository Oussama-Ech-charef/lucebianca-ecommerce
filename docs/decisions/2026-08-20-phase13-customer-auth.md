# Phase 13 — Customer authentication

Date: 2026-08-20

Roadmap item **3.7 (Login / Register / Account)**: customer login, register
with Google sign-in, and an account page. Scoped strictly to **customer**
auth — admin auth, payment, and completed phase 9/11/12 work were untouched.

## A. Backend audit — what already existed

Customer auth was wired in phase 2, so the backend work was narrower than
the roadmap wording suggested:

| Piece | Status |
| --- | --- |
| `POST /api/auth/register` / `login` / `refresh` / `logout` | **existed** (`AuthController` + `AuthService`: bcrypt `password_hash`, JWT HS256, refresh-token rotation with SHA-256-hashed storage, 422/409/401 shapes) |
| `users` table + `User` model/repo (`toArray()` never exposes the hash) | **existed** |
| `POST /api/auth/google` | **501 stub** — `AuthService::loginWithGoogle` threw |
| `users.google_id` | **missing** (no migration 004) |
| Account API (own profile + own orders) | **missing** |
| Order attribution (`orders.user_id`) | **missing** — `insertOrder` hardcoded NULL (guest checkout) |

## B. Google OAuth — server-side ID-token verification

The storefront "Sign in with Google" button (Google Identity Services) hands
the API a JWT `id_token`. Decisions:

- **Verify via Google's tokeninfo endpoint**
  (`https://oauth2.googleapis.com/tokeninfo?id_token=…`) rather than a
  hand-rolled JWKS/RS256/DER implementation (no external PHP library exists,
  and rolling RSA verification with native crypto is riskier than delegating
  the signature check to Google). New `GoogleOAuthService` then hard-checks the
  claims that matter locally: `aud` must equal `GOOGLE_CLIENT_ID`, `iss` must
  be Google's, `exp` must not be passed, and `email_verified` must be true.
- **`GOOGLE_CLIENT_ID` in `api/.env`** (gitignored; same id shipped to the
  browser as `NEXT_PUBLIC_GOOGLE_CLIENT_ID` — a public identifier, but the API
  re-checks `aud`, so tokens minted for another client are rejected).
- **Create-or-link by verified email**: a Google login with an email that
  already belongs to a password user links `google_id` to that user (no
  duplicate account); a brand-new email creates a user with an unusable random
  password hash — `password_hash` stays NOT NULL and `password_verify()` always
  fails for Google-only accounts (they have no password).
- Migration **`004_add_users_google_id.sql`**: `google_id VARCHAR(255) NULL
  UNIQUE` (one Google account can never attach to two users), added as a new
  numbered migration — 001/002/003 and `schema.sql` untouched.

## C. Account API + order attribution

- `GET /api/account` → the authenticated customer's profile (never the
  password hash). `GET /api/account/orders` → the customer's **own** orders,
  paginated with the same `{data, meta}` shape as the admin listing.
  Both behind `AuthMiddleware('user')` — an admin token (role `admin`) is
  rejected, and a customer can only ever read their own rows.
- `POST /api/orders` gained **optional** auth: if the request carries a valid
  customer Bearer token, `orders.user_id` is set so the order appears on
  `/account`; a missing/invalid/expired token degrades gracefully to guest
  checkout (`user_id` NULL) and never blocks ordering. No route change — the
  controller resolves the id itself.

## D. Frontend — separate customer session

- Session key **`lucebianca:customer:session:v1`** in localStorage — a
  third, deliberately separate store from the admin session
  (`lucebianca:admin:session:v1` + `lb_admin_session` cookie) and the cart
  (`lucebianca:cart:v1`).
- **No proxy gate**: `src/proxy.ts` still matches only `/admin/:path*`; the
  storefront is public and `/account` protection is a **client-side redirect**
  to `/login` (the guard reads localStorage imperatively so a saved session is
  honored on first paint). An admin session never satisfies a customer route
  and vice versa.
- Reactive state via `useSyncExternalStore` + module-level cache (the
  cart-context pattern) so the header account badge and `/account` re-render
  the instant the session changes.
- `/login`, `/register`, `/account` are storefront-branded pages (same
  `font-serif` headings, neutral palette, `inputClass` from the checkout
  form). Checkout now passes `getCustomerSession()?.token` into
  `placeOrder()`, so a logged-in order is attributed and appears in history.
- Google button (`google-signin-button.tsx`): initializes GIS only when
  `NEXT_PUBLIC_GOOGLE_CLIENT_ID` is set; otherwise renders a disabled button
  with a config note. Google's own OAuth cannot be exercised in tests without
  real credentials — verified instead that the endpoint returns a clean 401
  (not 501) for a bogus token.
- Header: logged-out shows a user icon linking to `/login`; logged-in shows an
  initials badge linking to `/account` (both 40×40 tap targets).

## E. Verification

- `php -l` clean on all 10 touched PHP files.
- API smoke (curl-level): register → login → `GET /api/account` (profile, no
  hash) → `GET /api/account/orders` (empty) → order with token (row got
  `user_id`, appears in account history) → order without token (row got
  `user_id NULL`) → no token / admin token on `/api/account` → 401 →
  bogus Google token → 401.
- `npm run lint` 0 errors; `npm run build` clean; `/login`, `/register`,
  `/account` all in the route table (`○` static).
- Playwright-core + system Edge E2E smoke: **15/15 PASS** — register →
  redirect to `/account` + profile + initials badge → logout → `/account`
  redirects to `/login` + sign-in icon → login → place COD order while logged
  in → order-confirmation → `/account` lists the order with its total →
  logout → `/account` redirects → customer session cannot reach
  `/admin/orders` (admin login shown) and creates no `lb_admin_session`
  cookie → Google endpoint rejects a bogus token with 401.
- Test data cleaned up afterwards (test users/orders/refresh tokens removed,
  variant stock restored). One harness note: `Intl.NumberFormat("fr-MA")`
  separates the amount and "MAD" with a non-breaking space.

## Notes / follow-ups

- Google OAuth's full flow is untestable locally without real Google
  credentials; the config point is `GOOGLE_CLIENT_ID` in `api/.env` +
  `NEXT_PUBLIC_GOOGLE_CLIENT_ID` in `frontend/.env.local`.
- `orders.user_id` is nullable by design (guest checkout stays supported).
- Customer refresh-token reuse of `POST /api/auth/refresh` is already
  implemented (phase 2); the storefront does not yet auto-refresh an expired
  access token — a 401 on `/api/account` clears the session and returns to
  `/login`.