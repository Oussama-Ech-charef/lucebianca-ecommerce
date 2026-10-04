# Payment status, shipping totals, inventory policy (2026-10-04;
# revised 2026-10: atomic inventory release, split return URLs)

## Payment status rule (authoritative)

- Every order is created with `payment_status='pending'` regardless of method
  (`api/src/Repositories/OrderRepository.php::insertOrder`).
- `paid` is reached only via:
  1. Card callback success (`POST /api/payments/callback` verifies HMAC,
     order existence, `payment_method='card'`, amount equality, then
     `OrderService::markCardPaymentPaid()` — allowed only from `pending` on
     an unreleased, uncancelled order; replays return paid idempotently), or
  2. Explicit admin confirmation (`PUT /api/admin/orders/{id}`
     `{"payment_status":"paid"}`).
- A late success for an already failed/cancelled/expired (released) card
  order is rejected (409) — paid can never resurrect a released order.
- COD/WhatsApp are **never auto-paid**. UI labels say "Pending confirmation"
  (checkout, confirmation, admin, email). Dashboard "realized" rule counts
  `paid` OR (`cod`/`whatsapp` + `processing`/`shipped`/`delivered`) so pending
  COD still contributes once fulfilled, while `cancelled` never does.

## Card return flow (customer page vs. server callback)

- `CMI_CALLBACK_URL` = server-to-server endpoint (`POST
  /api/payments/callback`). The customer never sees it.
- `CMI_RETURN_URL` = storefront payment-page base (defaults to
  `SITE_URL/payment`). Initiation builds per-order customer returns
  `/payment/{orderId}?token=<lookup_token>&pay=success|failure` (token only
  for guests); the HMAC covers these exact URLs. `GET /api/payments/config`
  exposes availability without secrets.
- `/payment/[id]` polls `GET /api/payments/status/{orderId}` (guest token or
  owner session) and renders pending/paid/failed/retry/confirmation states;
  on arrival with `?pay=success|failure` it auto-polls (the server callback
  may still be in flight). A retry after failure atomically re-reserves
  stock first (`reacquireInventoryForRetry` — 409 when sold out).

## Shipping totals (single source of truth)

- Server computes `shipping_cost` from `settings.shipping_cost` /
  `free_shipping_threshold` (`OrderService::calculateShippingCents`):
  free when `threshold > 0 && subtotal >= threshold`, else flat cost
  (0 when unconfigured). Regions are informational on `/shipping` until the
  owner defines per-region rates (single flat rate today).
- Stored per order: `subtotal_amount` + `shipping_cost` + `total_amount`
  (`016_add_orders_shipping_breakdown.sql`, backfilled `subtotal=total`).
- Every surface renders the same breakdown: checkout estimate, cart estimate,
  confirmation page, WhatsApp message, admin detail, order-confirmation email.

## Inventory policy (stock is reserved, never lost, never double-restored)

- Stock decrements atomically at order creation (guarded `UPDATE ...
  WHERE stock >= qty` inside the order transaction).
- Every release path funnels through ONE atomic transaction
  (`OrderService::releaseInventoryOnce()` + callers `markCardPaymentFailed`,
  `cancelOrder`, `expireStaleCardOrder`):
  1. the order row is locked (`SELECT ... FOR UPDATE`) and proceeds only
     when `orders.inventory_released_at IS NULL` (migration `020`),
  2. the release is claimed with a conditional `UPDATE ... WHERE
     inventory_released_at IS NULL` (exactly one claimant wins),
  3. every order line's stock is restored,
  4. all commits together.
- A concurrent loser (failed callback vs. expiry cron vs. customer/admin
  cancellation racing the same order) finds the marker set and returns
  safely without incrementing again. Repeated cancels/callbacks are safe
  no-ops; the cron skips already-handled orders.
- Stock is restored **exactly once** when:
  - card callback reports `failed`,
  - customer cancels (`POST /api/orders/{id}/cancel`, pending/processing only),
  - admin cancels (`PUT ... {"status":"cancelled"}` from pending/processing),
  - expiry cron (`api/scripts/expire-card-pending.php`, default 30 min,
    card-pending only; COD/WhatsApp never expire automatically).
- Cancelled is terminal; shipped/delivered cannot be cancelled (409).
- Paid orders are never cancelled automatically (409 "refund first",
  checked before and under the row lock): cancelling a paid order would
  keep the money while returning the goods to the shelf. Unpaid COD,
  WhatsApp, and failed/pending card orders cancel normally.
- Run expiry via cron every 15 min:
  `php api/scripts/expire-card-pending.php` (see script header for the
  crontab line).

## Owner decisions still required

- Per-region shipping rates (currently one flat rate + free threshold).
- Whether COD/WhatsApp should ever auto-mark `paid` on delivery (currently
  manual admin confirmation; dashboard already treats delivered COD as
  realized revenue).
- CMI credentials (`CMI_CLIENT_ID/STORE_KEY/HASH_KEY/CALLBACK_URL`,
  plus `CMI_RETURN_URL` for the storefront return page — same value as
  `SITE_URL/payment` unless the gateway needs a distinct host) and
  `payment_card_enabled` toggle before offering card checkout.
