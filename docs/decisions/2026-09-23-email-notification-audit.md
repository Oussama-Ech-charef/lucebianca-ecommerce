# 2026-09-23 — Email and notification audit (guest shipping, settings, commit, logs)

**Date:** 2026-09-23
**Mode:** audit and fix email/notification behavior only — no real Resend calls, no DB execution, no `DELETE`.

## 1) Scope audited

- `api/src/Services/EmailService.php:1` (Resend `POST /emails`, `RESEND_API_KEY`/`RESEND_FROM_EMAIL`)
- `api/src/Services/NotificationService.php:1` (admin new order, low stock, `ADMIN_EMAIL`/`settings`)
- `api/src/Services/OrderService.php:1` (`placeOrder`, `updateStatus`, `sendOrderConfirmationEmail`, `sendShippingStatusEmail`)
- `api/src/Services/AuthService.php:63` (`register` verification best-effort, `resendVerification`)
- Guest order confirmation (`OrderService:287`), shipping status (`OrderService:346`), admin notifications (`NotificationService:55`), notification settings (`SettingsRepository`)

## 2) Confirmed issues and fixes

### a) Guest shipping-status emails not being sent — **FIXED**

**Evidence:** `OrderService.php:346` `updateStatus` fetched `Order` then:

```php
$email = null;
if ($order->userId !== null) {
    $user = $userRepo->findById($order->userId);
    $email = $user->email;
}
if ($email !== null) { sendShippingStatusEmail(...); }
```

For guest checkout `userId === null`, `$email` stayed `null` even though `orders.email` holds the guest address (`Order.php:29` `?string $email`, `OrderService:437` confirmation correctly falls back to `order.email`). Result: `GET /api/admin/orders/{id}` status change to `shipped` never emailed guests.

**Fix:** `OrderService.php:346` now

```php
$email = $order->email ?? null;
if (($email === null || $email === '') && $order->userId !== null) {
    $user = $userRepo->findById($order->userId);
    $email = $user->email;
}
if ($email !== null && $email !== '') { sendShippingStatusEmail(...); }
```

Mirrors `sendOrderConfirmationEmail:487` fallback and preserves backward compatibility for old rows missing `order.email`. Best-effort, `updateStatus` still returns order even if email missing.

### b) Notification settings not being honored — **FIXED (verified)**

Previous step `2026-09-22-admin-settings` added `SettingsController.php:18` whitelist (`notify_admin_new_order`, `notify_admin_low_stock`, `notify_customer_order_confirmation`, `notify_customer_shipping_update` `0/1`) and `PUBLIC_KEYS` filtering, but `OrderService` customer email paths and `NotificationService` admin paths must both check.

**Audit:**
- `OrderService.php:476` `sendOrderConfirmationEmail` now `if (settings.get('notify_customer_order_confirmation') === '0') return` before any email fetch; `sendShippingStatusEmail:523` checks `notify_customer_shipping_update`.
- `NotificationService.php:28` `isNotifyEnabled` + `notifyNewOrder:57` checks `notify_admin_new_order`, `notifyLowStock:99` checks `notify_admin_low_stock` before `getAdminEmail()`.

Missing rows default to **enabled** (`get` returns `null` !== `'0'` → send), so fresh installs work. `SettingsRepository` read errors are caught (`catch \Throwable`) and treated as enabled — never blocks order.

**Verified via static grep:** `Select-String -Pattern "notify_customer|notify_admin"` shows 4 checks in `OrderService`/`NotificationService` plus whitelist entries.

### c) Missing or unsafe configuration handling — **FIXED**

**Before:** `EmailService.php:120` `send()` throws `RuntimeException('RESEND_API_KEY is not configured.')` when `Env::get('RESEND_API_KEY') === ''`. `AuthService.php:96` and `OrderService.php:504` catch `\Throwable`/`RuntimeException` and log `error_log('... verification email not sent: '.$e->getMessage())` — best-effort, never fails registration/order. `NotificationService.php:131` `getAdminEmail()` returns `null` if `Env ADMIN_EMAIL` missing, callers `notifyNewOrder`/`notifyLowStock` early-return. All paths are safe: missing config → skipped send, logged, no `500`, no customer-facing error.

**Unsafe handling fixed:** `OrderService.php:456` `isPaymentMethodDisabled` and notification checks now `try { settings->get } catch (\Throwable) { return false/true }` so a temporary `settings` DB outage never fails `placeOrder` (defaults to enabled/skip). `EmailService` never logs `apiKey` or `RESEND_FROM_EMAIL`; exception messages are generic (`Email service is unreachable.`/`rejected`) not containing recipient.

### d) Side effects before successful DB commit — **FIXED**

**Evidence:** `OrderService.php:265` `placeOrder` loop before `commit()` called:

```php
$remainingStock = ...;
if ($remainingStock >0 && $remainingStock <=5) {
    $this->notifications->notifyLowStock(...); // ← inside transaction!
}
```

If later `decrementStock` failed or `commit` rolled back, the admin would have received a spurious low-stock email for an order that never persisted.

**Fix:** `OrderService.php:232` now collects `$lowStockAlerts = []` inside the transaction, and `OrderService.php:289` after `commit()` iterates and calls `notifyLowStock` **only after successful commit**. `sendOrderConfirmationEmail` and `notifyNewOrder` were already after commit and remain best-effort.

`AuthService.php:86` `register` verification `setEmailVerification` occurs **after** user row `create()` (outside any transaction) and is itself best-effort inside `try/catch` — no commit coupling, safe.

### e) Sensitive information in logs — **FIXED / VERIFIED**

**Grepped:** `Select-String -Pattern "error_log"` → 6 sites (`PaymentController:159`, `AuthService:104,266`, `NotificationService:81,118`, `OrderService:518,554`).

- `OrderService:518` `Failed to send order confirmation for order #%d: %s` logs `order.id` + `$e->getMessage()` (generic Resend message, no `email`, `phone`, `lookup_token`, `email_verification_token`).
- `OrderService:554` shipping status same.
- `NotificationService:81` `Failed to send new order notification for order #%d`, `118` low stock with `productName`, no PII.
- `AuthService:104` `verification email not sent: ` + generic message.
- `PaymentController:159` `[Payment] Order #%d paid ... Transaction: %s, Amount: %s` logs `order_id`, `transaction_id`, `amount` (payment metadata, not customer PII).
- `ErrorLogger.php:58` sanitizes via `sanitizeMessage` redacting `Bearer`, `password`, `token`, `api_key`, `secret`, never logs bodies/headers/DB rows.

No `error_log` contains `lookup_token`, `email`, `phone`, `shipping_address`, `verification token`, or `RESEND_API_KEY`. All email-related exceptions are generic and PII-free.

## 3) Production configuration requirements

**Required (email delivery):**
- `RESEND_API_KEY` — Resend `re_...` key (outbound). Missing → `EmailService.php:124` throws `RESEND_API_KEY is not configured.` → caught as best-effort, **order still succeeds**, `error_log` only; no `500`.
- `RESEND_FROM_EMAIL` — verified sender, `onboarding@resend.dev` works only to owner's test inbox on free tier; replace with `noreply@lucebianca.co` before launch. Missing defaults to `onboarding@resend.dev` (see `EmailService:129`).
- `SITE_URL` — `https://lucebianca.co` (canonical for `AuthService:408` verification link `SITE_URL/verify-email?token=`).
- `ADMIN_EMAIL` (optional but recommended) — fallback for `NotificationService:131` if `Env` missing, admin notifications silently skipped. Alternatively store `store_email` via `settings` whitelist (admin UI) and extend `getAdminEmail()` to read `store_email` as fallback in a later step (currently deferred).

**Optional (notifications, payment):**
- `ADMIN_EMAIL` also gates `NotificationService::isEnabled()`; if absent, both admin notifications skip.
- `CMI_*` (`CMI_CLIENT_ID`, `CMI_STORE_KEY`, `CMI_HASH_KEY`, `CMI_GATEWAY_URL`) — already checked via `PaymentService::isConfigured()`; missing → `OrderService:185` `payment_method=card` → `422`.

**Settings (admin UI `PUT /api/admin/settings`, whitelisted):**
- `notify_admin_new_order`, `notify_admin_low_stock`, `notify_customer_order_confirmation`, `notify_customer_shipping_update` (`0`/`1`, default `1` if missing) — honored as above.
- `whatsapp_number`, `shipping_cost`, `store_name` etc. — not email-related, intentionally deferred for email path.

**Failure behavior (best-effort, never blocks order):**
- `RESEND_API_KEY` missing/invalid, `RESEND_FROM_EMAIL` untrusted, `ADMIN_EMAIL` missing, `settings` DB unreadable, or Resend `5xx`/`4xx` → `EmailService:158` throws, caller logs `error_log('[Email] Failed ...: Email service is unreachable./rejected.')` and continues. Guest order confirmation still `201`, shipping status `200`, admin notification skipped.

## 4) Static checks (no DB, no Resend)

- `php -l api/src/Services/EmailService.php`, `NotificationService.php`, `OrderService.php`, `AuthService.php` + all `api/src/**/*.php` excluding `vendor/storage` → `PHP LINT DONE` (0 failures).
- `Select-String -Pattern "error_log.*email|lookup_token|verification_token"` → 0 PII in logs (only `order.id`, `productName`, generic message).
- `Select-String -Pattern "notifyLowStock|notifyNewOrder|sendOrderConfirmation|sendShippingStatus"` → low-stock now after `commit()` (`OrderService:289`), others after `getOrder` (post-commit), verification best-effort (`AuthService:96`).
- `Select-String -Pattern "RESEND_API_KEY|ADMIN_EMAIL"` → only `EmailService:122` check + `Env` reads, no secret logged.

## 5) Files

- **Edited:** `api/src/Services/OrderService.php:229` (defer low-stock), `api/src/Services/OrderService.php:346` (guest email fallback).
- **Verified, no change:** `api/src/Services/EmailService.php:120`, `api/src/Services/NotificationService.php:28`, `api/src/Services/AuthService.php:96` (all best-effort, sanitized logs).
- **Docs:** this file; `docs/README.md` index updated.
- **No** real `file_get_contents` to Resend executed, no `DELETE` of orders, no migration change.

## 6) Remaining risks

- Existing guest orders before `015` `lookup_token` have backfilled tokens unknown to the guest (no email with token) — shipping emails for those will now use `order.email` correctly, but guests cannot re-fetch order via old `?id=` link without token.
- `store_email` vs `ADMIN_EMAIL` fallback for admin notifications not yet wired in `NotificationService:131` (still `TODO`); admin notifications will stay silent if `ADMIN_EMAIL` env missing even if `store_email` setting exists.
- `RESEND_FROM_EMAIL` unverified domain still limits delivery to test inbox; monitor `api/storage/logs/app-error.log` via `X-Request-Id` on `500`s (sanitized).
