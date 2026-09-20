# Phase 9 — Admin login + order management

Date: 2026-08-19

Covers roadmap item 9, scoped to exactly what it names: the admin **login**
(`/admin/login`, spec section 7) and admin **order management**
(`/admin/orders` + the backend `GET /api/admin/orders` and
`PUT /api/admin/orders/{id}` from spec 5). The other admin stubs
(dashboard, products, customers, categories, reviews, messages, settings)
stay unimplemented — later phases.

## A. Backend — admin orders endpoints

`Admin\OrdersController` (already routed behind `AuthMiddleware('admin')`
in `api/routes.php` — untouched):

- **`GET /api/admin/orders`** — paginated list with an optional status
  filter, via `OrderRepository::paginate()`: light rows (`id`,
  `customer_name`, `phone`, `total_amount`, `payment_method`,
  `payment_status`, `status`, `created_at`) plus an `item_count` subquery,
  ordered `created_at DESC, id DESC`, wrapped in the project-wide
  `{data, meta:{page, per_page, total, pages}}` shape. `page`/`per_page`
  clamp (default 12, cap 100); a `status` outside the enum → 422.
- **`PUT /api/admin/orders/{id}`** — via `OrderRepository::updateStatus()`
  → `OrderService::updateStatus()`. Only `status` and `payment_status` are
  accepted, each validated against `Order::STATUSES` /
  `Order::PAYMENT_STATUSES` (404 for a missing order, 422 for an invalid or
  empty body). Returns the updated order with its joined items (same shape
  as the public `GET /api/orders/{id}`).

The enum unions (`status` `pending|processing|shipped|delivered|cancelled`,
`payment_status` `pending|paid|failed`) are the single source of truth on
`Order`; the frontend mirrors them as `OrderStatus`/`PaymentStatus` in
`src/lib/types.ts`.

## B. Admin session — separate from the customer storefront

Admins are a fully separate table (spec section 4); there is no public admin
registration (accounts are created offline via `api/scripts/create-admin.php`).

- Login is `POST /api/admin/auth/login` (public, the ONE public admin
  endpoint) → `{token, refresh_token, admin}` (unwrapped, not under
  `data` — verified against the actual response).
- The admin session lives in **`localStorage` under its own key
  `lucebianca:admin:session:v1`** — deliberately isolated from the customer
  cart key `lucebianca:cart:v1` (and any future customer auth storage).
  `refresh_token` is persisted for parity; `/api/admin/auth/refresh` is a
  later phase, so a 401 on the orders API clears the session and redirects.

## C. Route protection — Next 16 `proxy.ts` + client guard

Next 16 renamed `middleware.js` → **`proxy.ts`** (file `src/proxy.ts`,
export `proxy`). The proxy can't read localStorage, and the admin JWT lives
there (needed for the Bearer header), so login **also writes a dedicated
gate cookie** `lb_admin_session` (non-httpOnly, `SameSite=Lax`, `path=/`).

- `proxy.ts` gates `/admin/:path*` by cookie presence: `/admin/orders`
  without the cookie redirects to `/admin/login` before any protected HTML
  renders — no flash of protected content; `/admin` → `/admin/orders` when
  authed, else `/admin/login`; `/admin/login` is public.
- Presence is only a gate — the JWT itself is verified by the API. The
  protected shell's **client guard** (`admin-shell.tsx`) re-checks
  localStorage on mount and clears both cookie + storage on desync, so a
  stale cookie or expired token still lands on `/admin/login`.

## D. Storefront chrome isolation + admin UI

- Root `layout.tsx` now renders a client `StorefrontShell` that checks
  `usePathname()` and hides the storefront chrome (Header, Footer,
  WhatsApp button, CartProvider) for `/admin/*` — the admin area renders
  without the shop shell.
- `/admin/orders` (`orders-client.tsx`) is **mobile-first**: stacked cards
  below `lg`, a table at `lg+` with an expanded `colSpan` detail row. Row
  details use the existing public `GET /api/orders/{id}` (the same join as
  the customer confirmation page — one source of truth). Status changes PUT
  in place and update the row without a reload.

## E. Fixes surfaced during verification

- **`react-hooks/set-state-in-effect`:** the list effect originally called a
  `loadOrders()` helper that set `loading` synchronously. Rewritten to the
  React-docs inline-async pattern (setState only after an `await`, plus a
  `cancelled` flag); manual page/filter changes set `loading` before the
  effect re-runs.
- **`OrderDetail` status typing:** the API's `status`/`payment_status` were
  `string`; typed them as `OrderStatus`/`PaymentStatus` so the admin update
  response flows through `AdminOrder` without casting.
- **Next 16 dev cross-origin resource block:** the E2E harness drives the
  dev server over `http://127.0.0.1:3000`, but Next 16 serves dev resources
  for the `localhost` host by default and returned **403 for every
  `/_next/static/chunks/*.js`** (React never hydrated, so the login form did
  a native GET submit). Fixed per the documented Next 16 option:
  `allowedDevOrigins: ["127.0.0.1"]` in `next.config.ts`.

## Verification

- `php -l` clean on all 4 changed PHP files (`Order`,
  `OrderRepository`, `OrderService`, `Admin\OrdersController`). `composer`
  is **not installed** on this machine, so `composer dump-autoload` wasn't
  run — the manual PSR-4 autoloader + existing `vendor/autoload.php` cover
  the new methods (no new classes).
- API smoke (Node, `127.0.0.1:8091` — 8090 is taken by an unrelated
  process, so `.env.local` points at 8091): admin login 200
  (`token_len=159`, top-level `token`); list `total=3`; `status=delivered`
  filter 0; invalid filter 422; no auth 401; PUT valid → 200 (item joined);
  invalid status 422; missing order 404; empty body 422; public
  `GET /api/orders/{id}` reflects the admin update (shared join).
- `npm run lint` → 0 errors; `npm run build` → clean; route table adds
  `/admin/login`, `/admin/orders` and the Proxy (middleware) route.
- Playwright-core / headless Edge harness → **23/23 checks**: real customer
  order placed (201); login form renders with no storefront header;
  unauthenticated `/admin/orders` and `/admin` redirect to `/admin/login`;
  form login → `/admin/orders`; new order visible; no storefront chrome on
  the admin; status dropdown change → `processing` persisted (re-fetched via
  API); expanded detail shows the SKU; `/order-confirmation?id=N` still
  renders (shared-join regression); logout → `/admin/login`; post-logout
  `/admin/orders` redirects again; **no horizontal overflow at 375px** on
  login and orders (card layout + status select visible); mobile status
  change → `delivered` persisted. 0 page errors.
- **Test data cleaned:** all `E2E-ADMIN-TEST` orders + their items deleted;
  order 11 restored to its baseline `pending/pending`.