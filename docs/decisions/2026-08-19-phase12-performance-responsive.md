# Phase 12 — Performance + Responsive testing

Date: 2026-08-19

Covers roadmap items 3.2 (ISR), 3.4 (performance/SEO-friendly), and 3.5
(responsive + mobile UX), scoped to: image audit (raw `<img>` → `next/image`),
lazy-loading/`priority` audit, ISR on `/product/[slug]`, a first-load JS record
across routes, and a Playwright/Edge responsive QA matrix at 375/768/1440px
across `/`, `/shop`, `/product/[slug]`, `/cart` (with an item), `/checkout`,
and `/order-confirmation`. Backend/API, payment, admin scope, and SEO metadata
(phase 11) were NOT touched.

## A. Image audit — all images use `next/image`

Searched `frontend/src` for raw `<img>` tags: **zero found**. Every image
already uses `next/image` with explicit `width`/`height`:

| Component | Image | Priority | Notes |
| --- | --- | --- | --- |
| `header.tsx:39` | logo | **yes** | sticky, above the fold on every page — correct |
| `header.tsx:244` | cart thumbnail | no | below the fold (cart panel) |
| `footer.tsx:9` | logo | no | below the fold |
| `hero.tsx:96` | hero art | no | decorative (`alt=""`, opacity-15, `sizes` set) |
| `cart-view.tsx:49` | cart thumbnail | no | `width`/`height` set |
| `gallery.tsx:29,52` | product images | no | `sizes` set |
| `product-card.tsx:15` | shop card image | no | `sizes` (25vw lg / 33vw sm / 90vw) |

**Priority audit:** `priority` is used **only** on the sticky header logo
(above-the-fold branding). Every other image loads lazily by default — correct.
No changes were needed for items 1–2 of the task.

## B. First Load JS — measurement

Next 16.3.1 builds no longer print a per-route size table (Turbopack or
webpack), so sizes were measured directly against the production server: each
route's HTML was fetched and every `/_next/static/*.js` chunk it references was
downloaded and summed (this is the real first-load JS, HTML excluded).

| Route | First Load JS | Chunks |
| --- | --- | --- |
| `/` | 735 KB | 10 |
| `/shop` | 598 KB | 8 |
| `/product/[slug]` | 598 KB | 8 |
| `/cart` | 607 KB | 9 |
| `/checkout` | 610 KB | 9 |
| `/order-confirmation` | 598 KB | 8 |
| `/admin/login` | 603 KB | 9 |
| `/admin/orders` | 603 KB | 9 |

All routes are dominated by two shared framework chunks (Next core
`794-…` ≈ 241 KB, React `4bd1b696-…` ≈ 201 KB) plus `polyfills` (≈ 113 KB);
the page-level delta between routes is small (~10–30 KB). **Flagged: the app
ships ~600 KB (gzip ≈ 180–200 KB) of JS on every page** because the storefront
shell (sticky header, cart, hero on `/`) is a client bundle. `/` is the
largest route (+137 KB over the rest) due to the hero. No obviously redundant
import was found (the shared chunks are framework code), so per the task no
deep bundle optimization was attempted. The 794/4bd1b696 chunk names come from
the webpack build used to inspect them; Turbopack and webpack builds of the
same app are equivalent for the app's own code.

## C. ISR on `/product/[slug]` — revalidate 3600

Per spec 3.2 ("use ISR for product pages so they stay fast and SEO-friendly"):
`export const revalidate = 3600` plus `generateStaticParams` was added to the
product page, and the product-detail fetch was given `next: { revalidate:
3600 }`.

**Gotcha discovered (Next 16):** `revalidate` alone on a dynamic route
accomplishes nothing — the build route table still listed `/product/[slug]` as
`ƒ (Dynamic)` and the response carried `Cache-Control: private, no-cache,
no-store`. Three things were needed for ISR to actually engage:

1. `generateStaticParams` (collects active product slugs by paginating
   `GET /api/products` via `meta.pages`) → the build now prerenders
   `/product/casual-luxury-tee` (`● (SSG)`).
2. The underlying `fetch` must be cached — `api()` (in `src/lib/api.ts`) had
   no `next` support and Next 16 does not cache `fetch` by default, so every
   request hit the live API. `RequestOptions` gained an optional `next` field
   (passed through to the fetch init), and `fetchProductDetail` now sends
   `{ next: { revalidate: 3600 } }` matching the route config.
3. `export const revalidate = 3600` stays as the route's revalidation window.

After the fix, `next start` responses for the product page show
`Cache-Control: s-maxage=3600, stale-while-revalidate=31532400` and
`x-nextjs-cache: HIT`. Consequences: a paused product or price change surfaces
within an hour (background revalidation), new slugs render on-demand
(`dynamicParams` defaults to true), and unknown slugs 404. No conflict with the
existing `getProduct` React `cache()` (per-request memoization) or
`generateMetadata` (runs within the same cached render). **Build-time note:**
`generateStaticParams` calls the live API, so the API must be reachable during
`next build` (true for the Docker/`npm run build` workflow here).

## D. Responsive QA — Playwright/Edge, 375/768/1440

Harness: Playwright-core driving system Edge (headless) against the dev server
(the API CORS allowlist is `localhost:3000`/`127.0.0.1:3000`, so checkout —
the only page that calls the API client-side — only works from that origin).
`/cart` and `/checkout` were tested with a cart seeded into
`lucebianca:cart:v1`; checkout was reached through the real flow (click
"Proceed to Checkout") to exercise the actual path.

Checks per page per viewport: HTTP 200, no horizontal overflow
(`scrollWidth ≤ clientWidth`), no console/page errors, expected content
visible, and all visible interactive elements inside the viewport.

| Page | 375×667 | 768×1024 | 1440×900 |
| --- | --- | --- | --- |
| `/` | PASS | PASS | PASS |
| `/shop` | PASS | PASS | PASS |
| `/product/casual-luxury-tee` | PASS | PASS | PASS |
| `/order-confirmation?id=11` | PASS | PASS | PASS |
| `/cart` (with item) | PASS | PASS | PASS |
| `/checkout` (via cart) | PASS | PASS | PASS |

**18/18 PASS.**

### Tap targets

Measured at 375px: interactive elements below 40px height. The header
search/cart/menu icon buttons (36×36 via `p-2`) and the product variant size
pills (38px tall via `py-2`) were the only real misses — both were bumped to
≥40px (`flex h-10 w-10 items-center justify-center` on the header buttons,
`min-h-10` on the pills). Remaining items are advisory-only and standard:

- text links (e.g. a "Shop" link, 20px tall) — normal for text navigation;
- cart quantity steppers (−/+ at 36×30) — compact by design;
- the checkout payment radio (13×13) — native input whose label provides the
  hit area.

### Pre-existing observation (out of scope, not changed)

Hard-loading `/checkout` with a saved cart can redirect to `/cart` once: the
empty-cart guard (`checkout-form.tsx:64`) reads the cart during hydration,
before the `useSyncExternalStore` client snapshot (from localStorage) lands, so
a direct/refresh load can see an empty cart for a frame. The normal flow
(cart → "Proceed to Checkout", client-side navigation) never hits it, the cart
survives the redirect, and checkout is reached via the app's own path. Left
untouched because payment/checkout behavior is out of phase-12 scope.

## Verification

- `npm run lint` → 0 errors; `npm run build` → clean; route table now shows
  `/product/[slug]` → `/product/casual-luxury-tee` as `● (SSG)`.
- ISR confirmed on `next start`: product responses return
  `Cache-Control: s-maxage=3600, stale-while-revalidate=31532400`,
  `x-nextjs-cache: HIT`.
- Responsive matrix: 18/18 PASS (evidence in section D); after the tap-target
  fix, undersized interactive elements dropped from 3–6 to 1–4 per page (all
  advisory text links/steppers/radio).