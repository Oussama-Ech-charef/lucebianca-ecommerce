# 2026-09-21 — Order and payment privacy (lookup token, callback hardening)

**Date:** 2026-09-21
**Mode:** security fix — no real payment provider, no `DELETE` of orders/data, no DB execution, no integration tests.

## 1) Threat model

**Assets:** customer PII and order confidentiality per order: `customer_name`, `phone`, `email`, `shipping_address`+`city`+`postal_code`, `payment_method`/`payment_status`, `order_items` (product/size/color/sku/image + `price_at_purchase`×`quantity`), `total_amount`.

**Attackers & vectors:**
- **Enumeration via sequential IDs:** `GET /api/orders/{id}` was *public* (`routes.php:90` `get('/api/orders/{id}', [OrderController::class, 'show'])` without middleware, comment “anyone with an order id can read it”). IDs are auto-increment (`api/src/Repositories/OrderRepository.php:30` `INSERT` returns `lastInsertId`), trivially guessable `?id=1…N`. Same for `GET /api/payments/status/{orderId}` (`routes.php:95` public) and `POST /api/payments/initiate` (`routes.php:93` CSRF-only, body `order_id` without ownership check) — all leaked `payment_status`/`total_amount` and gateway `form_fields`.
- **Frontend link leakage:** `frontend/src/app/order-confirmation/page.tsx:11` `searchParams: {id}` and `storefront.ts:100` `fetchOrder(id)` built `/api/orders/{id}` without token; `checkout-form.tsx:287` `router.push('/order-confirmation?id=${order.id}')` placed guessable ID in URL, logged in browser history/referrer.
- **Payment callback tampering:** `PaymentController::callback:92` used `$_POST` and `PaymentService::verifyCallback:113` checked `HASH` (`hash_equals` correct) and parsed `oid` → `order_id`, but did **not** verify: order existence before trust, `amount == order.total_amount` (tampered amount could mark small order as paid for large payment), `payment_method === 'card'` (COD/whatsapp must not be flipped to paid via forged POST), valid state transition (`pending`→`paid`/`failed` only), replay/idempotency (duplicate CMI POST or attacker replaying captured callback).

**Impact:** any unauthenticated user could iterate `GET /api/orders/{id}` and harvest PII for all guests and for logged-in users’ orders; similarly poll `payment/status` to learn who paid what.

## 2) Chosen protection — non-guessable single-purpose lookup token

**Principle:** guest checkout stays guest-friendly (no account required) but each order gets a 256-bit capability token that is *returned once* at creation and thereafter required for every privacy-sensitive read. Owned orders (`user_id NOT NULL`) allow either token **or** ownership via customer JWT (`sub`).

**Why token in DB, not JWT:** token is *single-purpose, order-scoped* (not user session) and works for guests without a session. Hashed storage would require hashing on lookup; plain `VARCHAR(64) UNIQUE` with `hash_equals` on compare is sufficient for a capability with `UNIQUE` enforcement and high entropy.

**DB:**
- `orders.lookup_token VARCHAR(64) NOT NULL UNIQUE` (`schema.sql:132` `lookup_token`, `database/migrations/015_add_orders_lookup_token.sql:1` UNTESTED). Generated as `bin2hex(random_bytes(32))` (`OrderRepository.php:35` `generateLookupToken` → 64 hex, 256-bit). Backfill for existing rows uses portable `LOWER(CONCAT(REPLACE(UUID(),'-',''),REPLACE(UUID(),'-','')))` (64 hex, MySQL+MariaDB) then `UNIQUE`/`NOT NULL` enforced. Generation reuses `OrderService.php:227` once per `placeOrder` transaction.
- `schema.sql:5` now `015`, `migrations/README.md:15` updated. Fresh installs: snapshot already contains column; upgrades: backup + run `015` alone.

**Model/Repository:**
- `Order.php:33` `public ?string $lookupToken`, `fromRow:57` `lookup_token`, `toArray:83-87` exposes `lookup_token` only when present (creator’s `POST /api/orders` `201` includes it; subsequent `GET` with token strips it unless caller already supplied `?token=` — `OrderController.php:129` `unset` when no token supplied).
- `OrderRepository.php:43` `insertOrder` now binds `:lookup_token`; `OrderRepository.php:69-97` `findAuthorized(orderId, authenticatedUserId, providedToken)` → `hash_equals` compare, `404` on mismatch to hide existence.

**API:**
- `POST /api/orders` (`OrderController.php:49`): on `201`, body `data` now includes `lookup_token` (via `Order::toArray`). Frontend stores it.
- `GET /api/orders/{id}` (`OrderController.php:105`): now reads `?token=` or `X-Order-Token` via `extractLookupToken:144` and `authenticatedCustomerId` (Bearer `user` JWT) → `OrderService::getOrderAuthorized:315` → `404` on failure (not `403`, to avoid confirming existence). Guest without token or with wrong token gets `404` even if order exists.
- `POST /api/payments/initiate` (`PaymentController.php:43`): same check → `OrderService::getOrderAuthorized`; body `lookup_token` or query/header token required for guest; owned orders allow `Authorization: Bearer <user>` with `sub == user_id`. Returns `400` if already `paid` (`paymentStatus`), `400` if not `card`, else gateway data.
- `GET /api/payments/status/{orderId}` (`PaymentController.php:147`): same authorized lookup.

**Frontend:**
- `storefront.ts:100` `fetchOrder(id, lookupToken?, authToken?)` → `?token=` query or `Authorization` header.
- `types.ts:208` `OrderDetail.lookup_token?: string`.
- `checkout-form.tsx:283` after `placeOrder`, `order.lookup_token` → `router.push('/order-confirmation?id=${order.id}&token=${order.lookup_token}')` (encoded). Non-guessable link; shareable only by holder.
- `order-confirmation/page.tsx:11` `searchParams: {id, token}`, `getOrder(id, token)` → `fetchOrder(id, token)`. `generateMetadata` and page both use token. No auth needed for guest.
- No token in logs: `ErrorLogger.php:124` already redacts `token`, `Bearer`, `secret`.

## 3) Payment callback hardening (`POST /api/payments/callback`)

Route stays public **without** `CsrfMiddleware` (`routes.php:94` no CSRF, CMI is external) and without order token (gateway has no token). Verification now in `PaymentController.php:92` *after* `PaymentService::verifyCallback:113`:

1. **Signature:** `PaymentService:131` `hash_equals(expected, received HASH)` — unchanged, `generateCallbackHash:188` uses `storeKey`+`ver3`.
2. **Order existence:** `OrderService::getOrder(result.order_id)` → `404` if missing (before amount/state checks).
3. **Payment method:** `existing->paymentMethod !== 'card'` → `400` (prevents flipping COD/whatsapp to paid).
4. **Amount equality:** `callback amount` vs `order.totalAmount` via `number_format((float)…,2)` → `400 Payment amount mismatch` on tamper (`PaymentController.php:129`).
5. **Valid state transitions:** only `pending` → `paid`/`failed`. If `current === target` → idempotent success without `UPDATE` (replay: CMI retries, attacker replay of captured `paid` POST). If `current !== 'pending'` and `current !== target` → `409 Invalid payment state transition` (`PaymentController.php:136-144`).
6. **Idempotency:** same logic — duplicate `paid` for already-`paid` returns `200 {"status":"received"}` without side effect; duplicate `failed` similarly. `OrderService::updateStatus` is called only when `current === 'pending'`.
7. **Replay protection:** hash check + state check + amount check; transaction `oid` includes `time()` (`PaymentService:66` `LB-{orderId}-{time}`) but callback `oid` is echoed back and verified via hash — no need for extra `payment_callbacks` table (scope kept minimal, no DB structure change per “do not delete”).

`PaymentService:113` still returns `valid`, `order_id`, `status` (`paid`/`failed`), `transaction_id`, `amount`, `message`; controller adds order-level checks.

## 4) What did **not** change

- No `DELETE` of orders/data, no real provider integration, no `POST /api/payments/initiate` card flow enabled (still `paymentMethod === 'card'` guard, frontend card disabled).
- Admin `GET /api/admin/orders` stays admin-JWT-only (not guest).
- `GET /api/account/orders` stays `AuthMiddleware('user')` scoped to `user_id` (`AccountController.php:57`).

## 5) Manual verification (run yourself, no DB tests executed by agent)

**Prerequisites:** backup (`mysqldump -u root -p lucebianca > backup.sql`), apply `015` if `lookup_token` missing:
```sql
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='lookup_token';
-- Expect VARCHAR(64) NOT NULL
SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='uq_orders_lookup_token';
```

**Guest privacy:**
```bash
# 1. Place guest order (no auth)
curl -s http://127.0.0.1:8090/api/csrf-token | jq -r .csrf_token  # → CSRF
curl -s -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"customer_name":"Test","phone":"0612345678","email":"test@example.com","shipping_address":"1 rue","city":"Casa","postal_code":"20000","payment_method":"cod","items":[{"variant_id":1,"quantity":1}]}' \
  http://127.0.0.1:8090/api/orders | jq .data.lookup_token  # save TOKEN
# 2. Fetch without token → 404 (not 200)
curl -i http://127.0.0.1:8090/api/orders/1
# → 404 {"error":"Order not found."}
# 3. With correct token → 200
curl -s "http://127.0.0.1:8090/api/orders/1?token=$TOKEN" | jq .data.customer_name
# → "Test" (PII only with token)
# 4. With wrong token → 404
curl -i "http://127.0.0.1:8090/api/orders/1?token=bad" # → 404
# 5. Enumerate other ID with same token → 404 (cross-order)
curl -i "http://127.0.0.1:8090/api/orders/2?token=$TOKEN" # → 404
```

**Owned order:**
```bash
# Login as customer → JWT
curl -s -H "X-CSRF-Token: $CSRF" -d '{"email":"user@example.com","password":"..."}' http://127.0.0.1:8090/api/auth/login | jq -r .token # → JWT
curl -s -H "Authorization: Bearer $JWT" "http://127.0.0.1:8090/api/orders/1?token=$TOKEN" | jq .data.payment_status # → with token
curl -s -H "Authorization: Bearer $JWT" "http://127.0.0.1:8090/api/orders/1" | jq .data.id # → also works via ownership without token (guest token fallback still allows +token)
# Other user's order with your JWT → 404
curl -i -H "Authorization: Bearer $JWT" http://127.0.0.1:8090/api/orders/999 # → 404
```

**Payment initiation/status:**
```bash
curl -i -H "X-CSRF-Token: $CSRF" -d '{"order_id":1,"email":"test@example.com"}' http://127.0.0.1:8090/api/payments/initiate # → 404 without token (now)
curl -s -H "X-CSRF-Token: $CSRF" -d "{\"order_id\":1,\"lookup_token\":\"$TOKEN\",\"email\":\"test@example.com\"}" http://127.0.0.1:8090/api/payments/initiate | jq .data.gateway_url # → with token and card order
curl -i "http://127.0.0.1:8090/api/payments/status/1?token=$TOKEN" # → 200 with limited fields
curl -i "http://127.0.0.1:8090/api/payments/status/1" # → 404
```

**Callback:**
```bash
# No signature → 400 Invalid payment signature
curl -s -d "oid=LB-1-000000&ProcReturnCode=00&Response=Approved&amount=199.00" http://127.0.0.1:8090/api/payments/callback | jq .error
# Amount mismatch → 400 (create order total 199.00, callback amount 1.00)
# Replay same paid callback twice → first 200 paid, second 200 idempotent (no state change, same 200)
# Invalid transition paid→failed → 409
```

**Frontend:**
- Place guest order → URL becomes `/order-confirmation?id=123&token=64hex` (check `checkout-form.tsx:283`).
- Open `/order-confirmation?id=123` without token → `NotFoundState` (404 handling).
- Open with `?id=123&token=correct` → renders `customer_name`, `phone`, `shipping_address`, `items`, `total_amount`.

## 6) Files

- **Edited:** `database/schema.sql:132`/`138`, `api/src/Models/Order.php:19`, `api/src/Repositories/OrderRepository.php:35`, `api/src/Services/OrderService.php:227`, `api/src/Controllers/OrderController.php:102`, `api/src/Controllers/PaymentController.php:43`+`92`+`147`, `frontend/src/lib/storefront.ts:96`, `frontend/src/lib/types.ts:208`, `frontend/src/app/checkout/checkout-form.tsx:277`, `frontend/src/app/order-confirmation/page.tsx:11`.
- **New migration:** `database/migrations/015_add_orders_lookup_token.sql:1` (UNTESTED, INFORMATION_SCHEMA guarded, backfill via `UUID()`).
- **Docs:** this file; `migrations/README.md:15` updated; no real payment requests, no deletions.

## 7) Remaining risks

- Existing guest orders before `015` have backfilled random tokens unknown to the guest (no email with link) — they become unreachable via old `?id=` link; logged-in guests can still find via `GET /api/account/orders` if they later create an account and email matches.
- Token in URL may appear in server access logs/referrer — single-purpose and non-guessable mitigates; alternative header `X-Order-Token` also supported but frontend uses query for bookmarkability.
- No rate-limit on `GET /api/orders/{id}` guessing — `404` on failure already hides existence, but adding `RateLimitMiddleware` for this route is future work.
- `PaymentService` still throws `RuntimeException` if CMI env missing — `PaymentController::initiate:75` maps to `503`.
