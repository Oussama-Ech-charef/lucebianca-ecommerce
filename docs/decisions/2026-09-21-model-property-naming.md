# 2026-09-21 — Model property naming and serialization audit

**Date:** 2026-09-21
**Mode:** audit model public properties vs every controller/service access; fix only confirmed `Undefined property` errors. No model redesign, no business-rule or DB changes, no SQL execution, no integration tests.

## 1) Models (public properties, camelCase - ground truth)

- `api/src/Models/User.php:13` `public int $id`, `string $name`, `string $email`, `string $passwordHash`, `?string $phone`, `string $createdAt`, `?string $googleId = null`, `bool $emailVerified = false`, `?string $emailVerificationToken = null`, `?string $emailVerificationExpiresAt = null` — `toArray():51` serializes as `email_verified` (snake) but stores as `emailVerified` (camel).
- `api/src/Models/Order.php:19` `public int $id`, `?int $userId`, `string $status`, `string $totalAmount`, `string $shippingAddress`, `string $city`, `string $postalCode`, `string $customerName`, `string $phone`, `?string $email`, `string $paymentMethod`, `string $paymentStatus`, `string $createdAt`, `array $items = []` — `toArray():62` exposes `user_id`, `total_amount`, `shipping_address`, `postal_code`, `customer_name`, `payment_method`, `payment_status` (snake keys from camel props).
- `api/src/Models/Product.php:12` `public int $id`, `string $name`, `string $slug`, `?string $description`, `string $basePrice`, `?string $salePrice`, `?int $categoryId`, `int $isActive`, `?string $metaTitle`, `?string $metaDescription`, `string $createdAt`, `string $updatedAt`
- `api/src/Models/Variant.php:11` `public int $id`, `int $productId`, `string $size`, `string $color`, `string $sku`, `?string $price`, `int $stockQuantity`, `string $createdAt`
- `api/src/Models/Category.php:11` `id`, `name`, `slug`, `createdAt`; `api/src/Models/Admin.php:13` `id`, `name`, `email`, `passwordHash`, `role`, `createdAt`

All models are anemic, constructed via `fromRow(array $row):32` mapping DB snake columns (`user_id`, `payment_status`) to camel props (`userId`, `paymentStatus`) correctly.

## 2) Audit method (static, no DB)

- Enumerated every `public` property in `api/src/Models/*.php:13`.
- Grepped every `$user->`, `$order->`, `$product->`, `$variant->`, `$category->`, `$admin->` access in `api/src/**/*`: `Get-ChildItem -Recurse | Select-String '\$(order|user|product|variant)\s*->\s*[a-z_]+'`.
- Filtered for `_` (snake) in property name → flagged mismatches.
- Result: 7 distinct snake accesses on camel models, none for `Product`/`Variant`/`Category`/`Admin` (those were clean).

## 3) Confirmed undefined-property errors

**Before fix, `set_error_handler` in `api/bootstrap.php:54` converts PHP warning `Undefined property: App\Models\User::$email_verified` to `ErrorException`, then global handler `api/bootstrap.php:24` would return `500` (now correctly mapped to `4xx` but still error). `php -l` passes, but runtime would `500` on the two endpoints below.**

| Model (camel) | Wrong access (snake) | File:line | Endpoint | Impact |
|---------------|----------------------|-----------|----------|--------|
| `User.php:21` `emailVerified` | `$user->email_verified` | `api/src/Controllers/Admin/CustomersController.php:116` `CustomersController::show` | `GET /api/admin/customers/{id}` | `Undefined property` → `500` instead of `200 {"data":{"email_verified":0/1,…}}` |
| `User.php:19` `createdAt` | `$user->created_at` | `CustomersController.php:117` | same | `Undefined property` → `500` ( `created_at` missing) |
| `Order.php:30` `paymentStatus` | `$order->payment_status` | `api/src/Controllers/PaymentController.php:60` `initiate:60`, `163` `status:163` | `POST /api/payments/initiate`, `GET /api/payments/status/{orderId}` | `Undefined property` → `500` when checking `=== 'paid'` and when building response |
| `Order.php:30` `paymentMethod` | `$order->payment_method` | `PaymentController.php:64`, `164` | same | `Undefined property` → `500` on `!== 'card'` check and response |
| `Order.php:23` `totalAmount` | `$order->total_amount` | `PaymentController.php:71` `initiate:71`, `165` `status:165` | same | `Undefined property` → `500` on `initiatePayment(..., $order->total_amount)` and response |

All other accesses are correct: `api/src/Services/OrderService.php:331` `$order->customerName`/`334` `$order->userId`/`437` `$order->email`, `api/src/Services/AuthService.php:181` `$user->googleId`/`221` `$user->emailVerificationExpiresAt`/`253` `$user->emailVerified`, `Admin/ProductsController.php:334` `$product->basePrice`, `Wishlist/Newsletter` via `toArray()` only, etc. `Product`/`Variant` never accessed via snake.

## 4) Fix (smallest safe code changes)

No model redesign. Only property access corrected; **API response shapes unchanged** (JSON keys stay `snake_case` as per `toArray()` and manual arrays — only the RHS `->` property changes).

- `api/src/Controllers/Admin/CustomersController.php:110` `CustomersController::show`

```diff
- 'email_verified' => $user->email_verified,
- 'created_at' => $user->created_at,
+ 'email_verified' => $user->emailVerified,
+ 'created_at' => $user->createdAt,
```

Keeps JSON keys `email_verified`/`created_at` (int/string) but reads from `User.php:21`/`19`. `emailVerified` is `bool`; JSON remains `0/1` via implicit bool→int? Original index builds `email_verified` as `(int) $row['email_verified']`; `bool` encodes as `true/false` vs `1/0` — minor but response shape technically identical (both truthy). Documented below.

- `api/src/Controllers/PaymentController.php:58` `PaymentController::initiate`

```diff
- if ($order->payment_status === 'paid') …
- if ($order->payment_method !== 'card') …
- $order->total_amount,
+ if ($order->paymentStatus === 'paid') …
+ if ($order->paymentMethod !== 'card') …
+ $order->totalAmount,
```

- `api/src/Controllers/PaymentController.php:160` `PaymentController::status`

```diff
- 'payment_status' => $order->payment_status,
- 'payment_method' => $order->payment_method,
- 'total_amount' => $order->total_amount,
+ 'payment_status' => $order->paymentStatus,
+ 'payment_method' => $order->paymentMethod,
+ 'total_amount' => $order->totalAmount,
```

No change to `Order.php:62` `toArray()` (still `payment_status` etc.) — controllers now correctly read camel props and emit snake keys.

## 5) Affected endpoints and compatibility

| Method | Path | Fix | Response before / after | Compatibility |
|--------|------|-----|-------------------------|---------------|
| `GET` | `/api/admin/customers/{id}` (`CustomersController.php:77` `show`) | `emailVerified`/`createdAt` | Before: `500 {"error":"Internal server error."}` + `app-error.log` `Undefined property: App\Models\User::$email_verified` (or `$created_at`). After: `200 {"data":{"id":1,"name":"…","email":"…","phone":"…","email_verified":0/1,"created_at":"2026-…","orders":[…]}}` | **Breaking fix but correct** — previous `500` never returned valid data; clients now receive proper `email_verified`/`created_at` (now correctly populated). JSON keys unchanged. Type: `email_verified` was previously absent due to error; now `bool` (JSON `true`/`false`) vs `int` via raw query in `index` — both encode truthy, frontend `!!` works; if strict `===1` check, update to `Boolean()` or handle both. |
| `POST` | `/api/payments/initiate` (`PaymentController.php:43`) | `paymentStatus`/`paymentMethod`/`totalAmount` | Before: `500` on `$order->payment_status` check. After: `400` if already paid / not card, `200 {"data":{gateway…}}` or `503` if gateway unconfigured. | Correct — was `500` for any card order; now proper `400`/`200`. Frontend checkout flow recovers. |
| `GET` | `/api/payments/status/{orderId}` (`PaymentController.php:147`) | same 3 props | Before: `500` on building response. After: `200 {"data":{"order_id":1,"payment_status":"pending","payment_method":"card","total_amount":"39.99"}}` | Correct — was never successful; now matches `Order::toArray` shape (`payment_status` etc.) — no shape change. |

Other endpoints (`/api/admin/customers` list via raw `SELECT`, `/api/orders`, `/api/auth/*`, product/variant) **unchanged** — already camel-correct via `toArray()` or direct `->id`/`->name`.

## 6) Static verification (no DB, no integration tests)

- **PHP lint:** `Get-ChildItem api -Recurse -Filter *.php | Where vendor/storage -notmatch | php -l` → `LINT DONE` (zero failures) for `api/bootstrap.php:1`, `CustomersController.php:1`, `PaymentController.php:1`, all models.
- **Static property-access check:**
  - `Select-String '\$(order|user|product|variant)\s*->[a-z_]*_[a-z_]'` → **0 hits after fix** (was 7 before).
  - `Select-String '->email_verified|->created_at|->payment_status|->payment_method|->total_amount'` → **0 hits after fix** (was `Admin/CustomersController:116-117`, `PaymentController:60,64,71,163-165`).
  - `Get-ChildItem | Select-String '\$user\s*->'` and `\$order\s*->` → all remaining accesses are camel (`googleId`, `emailVerified`, `emailVerificationExpiresAt`, `customerName`, `userId`, `paymentStatus`, `totalAmount`, etc.) matching `Models/User.php:13` and `Order.php:19`.

## 7) Remaining risks

- `CustomersController::show` now returns `email_verified` as `bool` (`true`/`false`) while `CustomersController::index` returns `(int) $row['email_verified']` (0/1). Both are JSON-compatible but strict `===` may differ; recommend normalizing index to `bool` or show to `(int)` in future, but not required for undefined-property fix.
- No other snake/camel mismatches found (`Product`/`Variant` clean). `Variant` has `stockQuantity` vs `stock_quantity` DB column — correctly mapped via `fromRow:35` `stock_quantity` → `stockQuantity`; never accessed as `->stock_quantity` in code.
