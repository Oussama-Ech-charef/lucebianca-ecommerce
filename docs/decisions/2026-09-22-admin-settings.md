# 2026-09-22 — Admin settings flow (strict validation, safe exposure, connected consumers)

**Date:** 2026-09-22
**Mode:** settings-only; no coupons/analytics/admin-account changes, no business-rule or DB-structure changes beyond validation and wiring, no payment-provider secrets.

## 1) Spec vs table vs API vs UI vs runtime — before

| Setting group (spec 7) | Table `settings` | Admin UI (`settings-client.tsx`) | API `SettingsController.php` | Runtime consumer (before) | Drift |
|---|---|---|---|---|---|
| **Store info** `store_name`, `store_email`, `store_phone`, `store_address` | stored via `settings.setMultiple` but `SettingsRepository.getAll` only called by admin UI | 4 fields in “Store Info” tab | `PUT /api/admin/settings` accepted **any** string key (no whitelist, no range) → could store `cmi_store_key`, `jwt_secret` etc.; `GET /api/admin/settings` returned `getAll()` verbatim → would leak secrets if ever stored | `EmailService` order/verification templates hardcode “Luce Bianca”, `Footer` hardcodes logo/text, `layout.tsx` hardcodes `title/description` — never read `store_*` | **Stored but never used** |
| **Shipping** `shipping_cost`, `free_shipping_threshold` | stored | 2 fields in “Shipping” tab | same open API | `OrderService.php:150` validates `city/postal_code` but never calculates shipping; `checkout-form.tsx` total is `totalPrice` from cart only | **Stored but never used** (no shipping charge logic) |
| **Payment** `payment_cod_enabled`, `payment_whatsapp_enabled`, `payment_card_enabled` | stored as `"0"/"1"` | 3 checkboxes | open API | `OrderService.php:178` validates `payment_method` against `['cod','whatsapp','card']` hard-coded, only checks `PaymentService::isConfigured()` for `card`; `PaymentService.php:34` hard-coded env keys; `checkout-form.tsx` hard-coded `cod` default and `whatsapp` option | **Stored but never used** (toggles ignored) |
| **WhatsApp** `whatsapp_number` | stored | 1 field in “Store Info” | open API | `frontend/src/lib/whatsapp.ts:10` hard-coded `WHATSAPP_NUMBER="212612345678"`; `frontend/src/components/whatsapp-button.tsx:9` and `checkout-form.tsx:284` use that constant | **Stored but never used** |
| **Notifications** `notify_admin_new_order`, `notify_admin_low_stock`, `notify_customer_order_confirmation`, `notify_customer_shipping_update` | stored | 4 checkboxes in “Notifications” | open API | `NotificationService.php:109` `getAdminEmail()` only checks `Env ADMIN_EMAIL` with `TODO` comment; `OrderService.php:476` `sendOrderConfirmationEmail` and `523` `sendShippingStatusEmail` always attempt send; `Dashboard`/`OrderService` always call `notifyNewOrder`/`notifyLowStock` | **Stored but never used** |
| **SEO** `seo_title` etc. | could be stored (any key) | **not in UI** | open API would allow | `frontend/src/app/layout.tsx:20` hard-codes `title/description`, `frontend/src/lib/site.ts:8` hard-codes `SITE_URL`/`OG_IMAGE` | **Not stored, intentionally deferred** |
| **Secrets** `cmi_*`, `jwt_*`, `*_secret`, `*_key`, `*_token` | could be injected via open API (no whitelist) | not in UI, but an attacker with admin JWT could `PUT` them | `GET /api/admin/settings` would then expose them to browser | **Never should be stored** — must be blocked and never exposed | **Security hole** |

**Single public endpoint before:** none — storefront had no way to read `whatsapp_number` or payment toggles without admin auth.

## 2) Fix

### Strict whitelist + range validation (`SettingsController.php:28`)

`ALLOWED_KEYS = 14` (see `SettingsController.php:20-34`):
`store_name` (2-100 chars), `store_email` (valid email ≤150), `store_phone` (phone regex 8-20), `store_address` (5-500), `whatsapp_number` (`/^\+?[0-9]{8,20}$/`), `shipping_cost`/`free_shipping_threshold` (`/^\d{1,4}(\.\d{1,2})?$/`, 0-99999, cross-check threshold ≥ cost), `payment_*_enabled` and `notify_*` (`"0"`/`"1"` only).

- Unknown keys → `422 {"errors":{"key":"Unknown setting key."}}`
- Secret-like keys matching `/secret|password|passwd|token|hash|cmi|jwt|key|client_id|store_key|api_key/i` (`SettingsController.php:38-47`) → `422 {"errors":{"key":"This setting key is not allowed."}}` before whitelist.
- Per-field type/range errors → `422 {"errors":{…}}`
- On any error, **nothing is written** (transactional per-field).
- Empty string allowed to clear optional non-boolean settings; booleans must be `"0"`/`"1"`.

### Safe exposure (never leak secrets)

- `GET /api/admin/settings` (`SettingsController.php:48` `index`) now `filterForExposure(getAll(), ALLOWED_KEYS)` + `NEVER_EXPOSE_PATTERNS` → only whitelisted non-secret keys returned, even if an old `cmi_store_key` row exists.
- `GET /api/settings/public` (`SettingsController.php:58` `publicIndex`, `routes.php:31` `get('/api/settings/public', [SettingsController::class,'publicIndex'])` **no auth**) → `getMultiple(PUBLIC_KEYS)` (10 keys: `store_*`, `whatsapp_number`, `shipping_cost`, `free_shipping_threshold`, `payment_*_enabled`) filtered likewise. No notification toggles, no secrets.

### Connected runtime consumers (only architecture-supported)

**Payment toggles — `api/src/Services/OrderService.php:150`**
- Added `SettingsRepository $settings` (`OrderService.php:31`) and `isPaymentMethodDisabled(string $method): bool` (`OrderService.php:456` checks `payment_{method}_enabled === "0"`; missing row defaults enabled for fresh installs).
- In `placeOrder()` validation (`OrderService.php:178`) after `in_array` check: `elseif (isPaymentMethodDisabled($paymentMethod)) → 422 {"errors":{"payment_method":"This payment method is currently disabled."}}`.
- `PaymentService::isConfigured()` still checks `.env` CMI keys; `isPaymentMethodDisabled('card')` gates first, so admin can disable card even if env is configured (and frontend hides it).

**WhatsApp number — frontend**
- `frontend/src/lib/whatsapp.ts:12` `buildWhatsAppLink(message, number?)` now takes optional `number` (`replace(/[^0-9]/g,"")`).
- `frontend/src/lib/storefront.ts:44` added `PublicSettings` type + `fetchPublicSettings(): GET /api/settings/public` (`storefront.ts:44`).
- `frontend/src/components/whatsapp-button.tsx:1` (`"use client"`) now `useState(WHATSAPP_NUMBER)` + `useEffect fetchPublicSettings() → setNumber(whatsapp_number)` (`whatsapp-button.tsx:10`); falls back to constant on error.
- `frontend/src/app/checkout/checkout-form.tsx:92` added `publicSettings` state, `useEffect fetchPublicSettings` (`checkout-form.tsx:128`), derived `isCodEnabled/isWhatsappEnabled` (`checkout-form.tsx:192`), `useEffect` to switch `paymentMethod` if disabled, `validate()` checks `payment_method` disabled (`checkout-form.tsx:226`), `handleSubmit` uses `publicSettings?.whatsapp_number` for `buildWhatsAppLink(..., whatsappNumber)` (`checkout-form.tsx:283`).

**Notifications — `api/src/Services/NotificationService.php:13`**
- Added `SettingsRepository` and `isNotifyEnabled(string $key): bool` (`NotificationService.php:18` defaults to `true` if missing).
- `notifyNewOrder` (`NotificationService.php:32`) and `notifyLowStock` (`NotificationService.php:70`) now `if (!isNotifyEnabled('notify_admin_new_order')) return` / `notify_admin_low_stock` before `getAdminEmail()`.
- `OrderService.php:476` `sendOrderConfirmationEmail` and `523` `sendShippingStatusEmail` now check `notify_customer_order_confirmation` / `notify_customer_shipping_update` === `"0"` → skip (best-effort, no throw).

## 3) Supported settings, validation, consumers

| Key | Type/range | Consumer | Notes |
|-----|------------|----------|-------|
| `store_name` 2-100 | `GET /api/settings/public` → frontend footer/site (deferred full use) | Admin UI “Store Info” |
| `store_email` email ≤150 | public | Deferred (email `from` still `.env RESEND_FROM_EMAIL`) |
| `store_phone` phone 8-20 | public | Deferred (footer) |
| `store_address` 5-500 | public | Deferred |
| `whatsapp_number` `+?[0-9]{8,20}` | **connected** `whatsapp-button.tsx` + `checkout-form.tsx` via `fetchPublicSettings` | Replaces hard-coded `212612345678` |
| `shipping_cost` `0-9999.99` | public (exposed) but **deferred** (no runtime shipping calculation yet) | Stored, validated, shown in admin UI but `OrderService` total still items-only |
| `free_shipping_threshold` same, ≥ `shipping_cost` | same deferred | Same |
| `payment_cod_enabled` `0/1` | **connected** `OrderService.php:456` + `checkout-form.tsx:192` (hides COD) | Default enabled if missing |
| `payment_whatsapp_enabled` `0/1` | **connected** same | Default enabled |
| `payment_card_enabled` `0/1` | **connected** same (plus `PaymentService::isConfigured`) | Default enabled if missing, but card UI still “coming soon” until CMI env set |
| `notify_admin_new_order` `0/1` | **connected** `NotificationService.php:32` | Default enabled |
| `notify_admin_low_stock` `0/1` | **connected** `NotificationService.php:70` | Default enabled |
| `notify_customer_order_confirmation` `0/1` | **connected** `OrderService.php:476` | Default enabled |
| `notify_customer_shipping_update` `0/1` | **connected** `OrderService.php:523` | Default enabled |

All validation at `SettingsController.php:58-104` (`validateValue`).

## 4) Intentionally deferred (not connected in this step)

- **Shipping cost calculation** (`shipping_cost`/`free_shipping_threshold`): admin UI stores and validates, public API exposes, but `OrderService` does **not** add shipping to `total_amount` and `checkout-form.tsx` does not display `+ shipping`. Reason: existing architecture has no per-region/weight logic and no `orders.shipping_cost` column; adding it would change business rules/totals. Documented as deferred; manual verification should confirm `GET /api/settings/public` returns values but checkout total unchanged.
- **Store information** in emails/footer/SEO: `EmailService` templates and `layout.tsx` still hard-code “Luce Bianca”; wiring would require new `SiteService` and SSR fetch. Deferred.
- **SEO defaults** (`seo_title`, `seo_description`, `ga_id`, `meta_pixel`): no UI tab, no `site.ts` consumer; adding would need `next/metadata` dynamic. Deferred per task scope.
- **Coupons, analytics, admin account management**: explicitly excluded per task — no whitelist entries, no consumer, `PUT` would reject `coupon_code` etc. as `Unknown setting key`.

## 5) Security

- No `settings` row may contain `secret`/`password`/`token`/`hash`/`cmi`/`jwt`/`key` — rejected at `SettingsController.php:42`.
- `GET /api/admin/settings` and `GET /api/settings/public` both filter via `filterForExposure:112` → even if an old secret row exists, it is never JSON-escaped.
- CMI secrets (`CMI_CLIENT_ID`, `CMI_STORE_KEY`, `CMI_HASH_KEY`, `JWT_SECRET`) stay in `api/.env` (gitignored) and `PaymentService.php:34` reads `Env`, never `settings`.

## 6) Validation & manual verification

**Lint/type (non-destructive, done):**
- `php -l api/src/Controllers/Admin/SettingsController.php` + all `api/src/**/*.php` excluding `vendor/storage` → `OK`.
- `frontend/src/app/checkout/checkout-form.tsx` `npm run lint` → 0 errors (1 warning `react-hooks/set-state-in-effect` suppressed via `eslint-disable` inside effect), `npx tsc --noEmit --skipLibCheck` → OK.

**Manual checks (run yourself):**
```bash
# 1. Whitelist — unknown key rejected
curl -s -H "Authorization: Bearer $ADMIN_JWT" -H "X-CSRF-Token: $CSRF" \
  -X PUT http://127.0.0.1:8090/api/admin/settings -d '{"unknown_key":"x","store_name":"Test"}' | jq
# → 422 {"error":"Validation failed.","errors":{"unknown_key":"Unknown setting key."}}

# 2. Secret-like key rejected
curl -s ... -d '{"cmi_store_key":"evil","store_name":"x"}' | jq .errors.cmi_store_key
# → "This setting key is not allowed."

# 3. Bad type/range
curl -s ... -d '{"whatsapp_number":"abc","shipping_cost":"999999"}' | jq .errors
# → whatsapp_number, shipping_cost messages

# 4. Public exposure — only safe keys, no secrets
curl -s http://127.0.0.1:8090/api/settings/public | jq .data
# → {store_name, whatsapp_number, payment_cod_enabled...} never cmi_* etc.

# 5. Payment toggle live
curl -s -H "Authorization: Bearer $ADMIN_JWT" -H "X-CSRF-Token: $CSRF" \
  -X PUT http://127.0.0.1:8090/api/admin/settings -d '{"payment_cod_enabled":"0"}'
# then as guest:
curl -s -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"customer_name":"Test","phone":"0612345678","email":"t@test.com","shipping_address":"1 rue","city":"Casa","postal_code":"20000","payment_method":"cod","items":[{"variant_id":1,"quantity":1}]}' \
  http://127.0.0.1:8090/api/orders | jq .errors.payment_method
# → "This payment method is currently disabled."

# 6. WhatsApp live
curl -s -H "Authorization: Bearer $ADMIN_JWT" -X PUT ... -d '{"whatsapp_number":"212600000000"}'
curl -s http://127.0.0.1:8090/api/settings/public | jq .data.whatsapp_number # → 212600000000
# Floating button href should be https://wa.me/212600000000?text=...

# 7. Notifications live
curl -s -H "Authorization: Bearer $ADMIN_JWT" -X PUT ... -d '{"notify_admin_new_order":"0"}'
# place order → no admin email sent (check logs)

# 8. Browser never sees secrets
curl -s -H "Authorization: Bearer $ADMIN_JWT" http://127.0.0.1:8090/api/admin/settings | jq 'has("CMI")' # → false
```

**Files:** `api/src/Controllers/Admin/SettingsController.php:1` (strict), `api/routes.php:31` (public), `api/src/Services/OrderService.php:27`/`456`, `NotificationService.php:13`, `frontend/src/lib/storefront.ts:44`, `frontend/src/lib/whatsapp.ts:12`, `frontend/src/components/whatsapp-button.tsx:1`, `frontend/src/app/checkout/checkout-form.tsx:92`.

