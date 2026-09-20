# Phase 5 — Shop storefront (`/shop` + `/product/[slug]`)

Date: 2026-08-17 (local-only notes, matches the docs convention so far)

Covers roadmap item 5: the two public storefront pages, server-rendered with
SEO metadata and JSON-LD. Backend data was verified against live responses
throughout; the only backend change was embedding the variant/image payloads
the pages require (below).

## A. API gap fixed — product payloads now carry variants + images

`GET /api/products/{slug}` returned a flat product (no variants, no images),
but the detail page needs size/color selection with per-variant price and
stock, plus a gallery. `GET /api/products` also carried no images, so shop
cards could never show a photo once the admin uploads one.

- `ProductController::show()` now embeds `data.variants`
  (`ProductVariantRepository::listByProduct`) and `data.images`
  (`ProductImageRepository::listByProduct`).
- `ProductController::index()` embeds `data[].images` per item. To avoid an
  N+1 on the listing, `ProductImageRepository` gained
  `allForProducts(array $ids)` — one `WHERE product_id IN (...)` query,
  grouped by `product_id` in the controller.
- No schema change; both repos already existed and were verified in phase 4.

## B. Next 16 conventions confirmed from the bundled docs

`frontend/AGENTS.md` warns this Next (16.3.1) differs from training data, so
the docs in `node_modules/next/dist/docs/` were read first. Confirmed:

- `params` / `searchParams` are **Promises** — every page does
  `const sp = await searchParams` (page.md: "must use async/await or React's
  `use`"). `searchParams` is a request-time API → `/shop` renders dynamic.
- The global `PageProps<'/shop'>` / `LayoutProps<"/">` helper types exist in
  this version (no import needed).
- `fetch` is **not cached by default** in Next 16 — the storefront always
  hits the live PHP API, so a paused product disappears immediately (spec
  requirement). No `cache`/`revalidate` knobs needed.
- `generateMetadata` also receives `params` as a Promise; docs recommend
  `React.cache` to share one product fetch between metadata and page (used).
- Streaming metadata is automatic for dynamic routes; bots get full HTML.

## C. `/shop` — listing + server-driven filters + pagination

- Page (`src/app/shop/page.tsx`, Server Component) reads the filter query
  params, calls `fetchProducts()` and `fetchCategories()` in parallel.
- **Filters are real URLs** (`?category=&size=&color=&min_price=&max_price=&page=`),
  mapped 1:1 onto the API's own query params. Every filter combination is a
  crawlable page (SEO), and the API already supports them all — no frontend
  sorting/filtering of a single payload.
- `FilterPanel` is a tiny Client Component (`useRouter`) that pushes the URL;
  no fetch happens on the client. It's keyed by `JSON.stringify(filters)` so
  soft navigations keep the inputs in sync with the URL.
- Category options come from `GET /api/categories`; size/color are free-text
  inputs because the API has no "available sizes/colors" endpoint (admins can
  add arbitrary size/color labels, so hardcoded lists would go stale).
- Pagination links preserve the active filters; page numbers come from
  `meta.pages`. Empty state and an "API unreachable" state are distinct and
  honest. Cards show the main image via `next/image`, else a placeholder
  initial (no broken images).

## D. `/product/[slug]` — detail with gallery + variant selection

- `generateMetadata`: `title` uses `product.meta_title` as `{ absolute }`
  (it already contains the brand, so the `%s | Luce Bianca` template would
  duplicate it), falling back to the product name. Description falls back
  meta → description → (omitted).
- `notFound()` for 404 (paused or unknown slug); network/API errors render a
  message rather than a misleading 404.
- **JSON-LD**: `schema.org/Product` with `image[]`, `sku` (first variant),
  and an `AggregateOffer` (`lowPrice`/`highPrice` across variant prices,
  `offerCount`, `availability` from stock). XSS-safe stringify per the docs
  (`.`replace(/</g, '\\u003c')`), native `<script>` tag.
- **Gallery (Swiper 14.1.0, spec-mandated):** installed `swiper` and built
  `gallery.tsx` (client) — 0 images → placeholder block, 1 image → single,
  multiple → Swiper with navigation + pagination. Fallbacks exist so the page
  is verified with today's no-image product; the real Swiper UI activates as
  soon as admin uploads photos.
- **Variant selection** (`variant-selector.tsx`, client): sizes/colors come
  entirely from `data.variants`. Size chips → compatible color chips (both
  derived from real variant combos). The selected variant drives the price
  (variant `price` else `base_price`) and stock label; out-of-stock options
  are disabled, an out-of-stock selection disables the button.
- **Add to Cart is intentionally inert**: it's a real disabled button with an
  explicit "cart arrives in a later phase" note — no fake cart, no dead
  client state. Cart/checkout are phase 6.
- `next.config.ts` gained `images.remotePatterns` for `res.cloudinary.com`
  (spec: product images are Cloudinary URLs; required for `next/image`).

## E. Verification evidence (real output)

- `npm run lint` → 0 errors; `npm run build` → compiled, TypeScript clean;
  routes: `/` static, `/_not-found` static, **`/shop` and `/product/[slug]`
  both `ƒ (Dynamic)`** (server-rendered on demand).
- Live (Next dev on :3000 talking to PHP on :8090):
  - `/shop` → 200, renders "Shop", the seeded "Casual Luxury Tee", "$39.99",
    the filter panel.
  - `/shop?size=M` → still shows the product (server-side filter works);
    `/shop?size=XXL` → "No products found" empty state.
  - `/product/casual-luxury-tee` → 200; `<title>` = "Casual Luxury Tee -
    Luce Bianca" (meta_title, no template duplication); JSON-LD present:
    `Product`/`AggregateOffer`, low=high "39.99", `offerCount: 2`, `InStock`,
    sku `CLT-M-BLK`; size M/L + color Black chips render; "Add to Cart"
    present.
  - `/product/does-not-exist` → 404.
- Backend: `php -l` clean on both changed files; the live API responses were
  spot-checked to include `variants` (M/Black, L/Black) and `images` after
  the change.

## Security / hygiene

No secrets involved this phase (no auth on public pages; token is not used).
`swiper` added to `package.json`/lock (no CVEs reported). Servers left
running: PHP `127.0.0.1:8090` and Next `localhost:3000`.

## Currency display (scoped fix)

Prices render in **MAD**, not USD — the business is Moroccan and payments
settle via CMI/Payzone in Moroccan Dirham. `formatPrice()` in
`frontend/src/lib/storefront.ts` now uses
`new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" })`,
whose verified output is `39,99 MAD` (comma decimal, NBSP, "MAD" label — the
standard Moroccan French convention; "MAD" / `fr-MA` was chosen after
actually running Node's ICU across `fr-MA`/`ar-MA`/`en-US`). The JSON-LD
`AggregateOffer.priceCurrency` on `/product/[slug]` was also flipped `"USD"`
→ `"MAD"` (schema.org requires the ISO 4217 code). No other hardcoded
currency strings existed. Verified: lint + build clean; `/shop` card and
`/product/casual-luxury-tee` selector both render `39,99 MAD`.

## Remaining for later phases

- Cart + checkout (phase 6): wire "Add to Cart" to `POST /api/cart`, real
  dropdown panel, quantity steppers capped by variant stock.
- Homepage featured-products slider (Swiper already installed), site chrome
  (header/nav/footer) shared across pages.
- Category CRUD + real size/color lists from the API (today: free-text inputs,
  category select from `/api/categories`).
