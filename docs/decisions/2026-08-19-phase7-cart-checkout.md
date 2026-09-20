# Phase 7 — Cart + checkout (client cart, guest checkout, COD + WhatsApp)

Date: 2026-08-19 (local-only notes, matches the docs convention so far)

Covers roadmap item 7: the client-side cart (React Context + localStorage),
the real header cart panel + badge (spec 3.6.3), the `/cart` page (spec 6),
guest checkout at `/checkout` (spec 3.7 + section 4), the
`/order-confirmation` page (spec 6), and the backend stubs
`POST /api/cart`, `POST /api/orders`, `GET /api/orders/{id}` (spec 5).
Payment gateway (CMI/Payzone) is roadmap item 8 — out of scope.

## A. Cart state — React Context + localStorage (no server cart)

Spec 5 exposes only `POST /api/cart` (validation) and `POST /api/orders`;
there is no server-side cart session. So the cart lives entirely on the
client, in `src/lib/cart-context.tsx`:

- **Storage:** `localStorage` key `lucebianca:cart:v1`. Corrupt/absent
  payloads start empty. Persists across reloads and client-side navigation.
- **API shape:** the store is exposed through **`useSyncExternalStore`** with a
  module-level cached snapshot + subscriber set. localStorage is read lazily on
  the first client snapshot, so there is no SSR hydration mismatch and no
  "loading" flash — the badge/panels reflect the real cart immediately after
  hydration. (An earlier `useState` + `useEffect` hydration gate tripped the
  Next 16 `react-hooks/set-state-in-effect` rule; the external-store pattern is
  the React-recommended replacement and also removed the gate entirely.)
- **Item shape** (what the product page captures at add-time): variant_id,
  product_id, product_slug, product_name, image_url, size, color, sku,
  unit_price (variant.price ?? base_price), **stock_quantity** (caps the
  quantity steppers), quantity.
- **Quantity steppers clamp to `1..stock_quantity`** everywhere (panel, /cart).
  The clamp is a UX convenience — live stock is re-validated at checkout, so a
  stale clamp can never over-sell.

## B. `POST /api/cart` — per-line verdict, never an opaque accept/reject

`CartController` + `OrderService::validateCart` return **one verdict per
line**, so the UI can explain exactly why a line is unavailable:

- `available: true` + authoritative `unit_price`, `stock_quantity`; sets
  `price_changed: true` when the client-supplied price differs from the
  server's (the client never trusts its own price — spec 5's
  `price_at_purchase` is server-computed at order time).
- `available: false` + `reason` (`variant_not_found` |
  `product_unavailable` | `insufficient_stock`) + `available_quantity` (the
  most that can be ordered).
- Always 200; `valid` is the aggregate. HTTP 422 is reserved for genuinely
  malformed bodies.

The checkout form calls it **once on load** (shows a per-line warning when
stock dropped) and **again at submit** (the last moment before ordering).

## C. `POST /api/orders` — transactional placement, no partial orders

`OrderController` → `OrderService::placeOrder` (spec 5: `orders.user_id`
nullable guest; `order_items.price_at_purchase` records the price at
purchase time):

1. Validates `customer_name`, `phone`, `shipping_address`, `payment_method`
   (`cod | whatsapp`; **`card` is rejected with a clear 422** — see D),
   and non-empty items. All prices/totals are **computed server-side in
   cents** from live `product_variants.price ?? products.base_price`.
2. Pre-checks stock per line → `ConflictException` 409 with a specific
   message ("only X left") when any line can't be fulfilled.
3. One transaction: insert `orders` (user_id NULL, status `pending`,
   payment_status `pending`) → insert `order_items` → guarded decrement per
   line:
   `UPDATE product_variants SET stock_quantity = stock_quantity - :quantity
    WHERE id = :id AND stock_quantity >= :min_quantity`.
   If any decrement affects 0 rows (a concurrent checkout drained stock
   between the pre-check and the UPDATE), the transaction is rolled back and
   a 409 is returned — **never a partial order**.
4. Returns 201 with the order + joined lines (product_name/slug, image_url,
   size/color/sku via the variant join).

`GET /api/orders/{id}` (public, per spec) → 404 when missing; used by
`/order-confirmation`. Note: the id is the only lookup key — anyone with the
URL can read an order. A lookup token is out of scope (flagged for a later
phase).

## D. Payment methods — honest-inert card, real COD + WhatsApp

- **Card (CMI/Payzone):** phase 8. The radio is **visibly disabled**
  ("Card payments arrive in a later phase — coming soon"), and the API
  **rejects** a `card` payment_method with 422 rather than silently
  downgrading it. Never fake — nothing card-like is accepted now.
- **Cash on Delivery:** submits `POST /api/orders` (payment_status stays
  `pending` until the store confirms delivery on the phone).
- **Order via WhatsApp (spec 3.7, `wa.me` deep link):**
  - **Decision: create the pending order via `POST /api/orders` FIRST**
    (`payment_method=whatsapp`), then open `wa.me` with a pre-filled message
    (order #, per-line items with size/color/price, total, name, phone,
    address). Rationale: the store gets the order in its system immediately
    (admin can see it, stock is held/decremented atomically — a chat alone
    would be just a lead with no stock guarantee), payment_status stays
    `pending` until the chat confirms payment/delivery, and if stock fails
    at order time we block the chat entirely instead of sending a
    half-truth. The confirmation page is the source of truth; the chat is a
    confirmation/arrangement channel.
  - Number is the shared placeholder `212612345678` in
    `src/lib/whatsapp.ts` (also powers the floating button via
    `buildWhatsAppLink`), flagged to replace before launch.
  - In headless Edge the `wa.me` link redirects to
    `https://api.whatsapp.com/send/?phone=…&text=…` (normal when WhatsApp
    isn't installed) — verified the popup carries the phone + pre-filled
    message with the order id.

## E. Pages

- **`/cart`** (spec 6): itemized list (thumbnail/letter-fallback, size ·
  color · SKU, unit price, steppers clamped to stock, per-line totals,
  Remove), order-summary aside (subtotal → total, "Delivery: calculated at
  checkout"), Proceed to Checkout. Empty state with CTA.
- **`/checkout`** (spec 6): guest form (full name, phone, address) with
  client validation; payment radios (COD, WhatsApp, card disabled); empty
  cart redirects to `/cart`; live re-validation on load and at submit (see
  B); server 409/422 errors surface verbatim in an alert with a link to
  review the cart.
- **`/order-confirmation?id=N`** (spec 6): server-rendered fetch of
  `GET /api/orders/{id}` (cache() shared with generateMetadata, 404 state),
  order #, items + totals, delivery details, method-specific confirmation
  note; a tiny client `ClearCart` clears the cart on mount (order already
  placed). `searchParams` is a Promise in Next 16 — handled with `await`.

## F. CORS — the storefront is on two hosts

The browser calls `POST /api/cart` and `POST /api/orders` from
`http://127.0.0.1:3000` (preflight: `application/json`), but the API only
whitelisted `http://localhost:3000`, so preflight returned no
`Access-Control-Allow-Origin` and checkout failed. (GETs worked because the
product/shop fetches run server-side in Node.) Fix: added
`http://127.0.0.1:3000` to `api/.env` `CORS_ALLOWED_ORIGINS` (and
`.env.example`) — both hosts resolve to the same dev server.

## G. Bug fixed — named-parameter reuse with native prepares

`decrementStock` reused the `:quantity` placeholder in both the SET and the
WHERE clauses. The API runs `PDO::ATTR_EMULATE_PREPARES => false`
(native prepares), and MySQL-native PDO rejects a placeholder used twice
(`SQLSTATE[HY093]: Invalid parameter number` → the first order attempt
returned 500). Fixed by binding the WHERE guard to a distinct name
(`:min_quantity`). Transaction rollback left zero partial rows (verified).

## Verification

- `php -l` clean on all 4 new/rewritten PHP files.
- API (Invoke-RestMethod, dev server `127.0.0.1:8090`):
  - `GET /api/orders/999` → 404 (was 501 stub).
  - `POST /api/cart` variant 6×2 → `valid:true, price_changed:false`;
    variant 7×100 → `valid:false, available_quantity:15`.
  - `POST /api/orders`: `card` → 422 clear message; empty items / missing
    name / bad phone → 422; valid COD → 201 (79.98, price_at_purchase 39.99);
    WhatsApp multi-line → 201 (159.96, both lines decremented); stock
    conflict ×100 → 409, no partial order.
- `npm run lint` → 0; `npm run build` → clean (route table adds `/cart`,
  `/checkout` static, `/order-confirmation` dynamic).
- Playwright-core / headless Edge 375×667 harness → **29/29 checks**:
  add-to-cart feedback + header badge; panel open, itemized totals, stepper;
  /cart render + totals; /checkout render, card disabled, COD default,
  client phone validation; COD order lands on confirmation with items/total/
  delivery details; **no horizontal scroll** on product/cart/checkout/
  confirmation (fixed a grid min-content blowout with
  `grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px]`); localStorage cart
  cleared after the order; empty-cart /checkout → /cart redirect; WhatsApp
  popup carries phone + order-id message; 0 page/console errors.
- **Test data cleaned:** all e2e/smoke orders and order_items deleted, stock
  restored (variant 6 → 20, variant 7 → 15), orders/order_items = 0.