# 2026-09-20 — API error handling (4xx contract, no 500 for expected errors)

**Date:** 2026-09-20
**Mode:** fix API error handling only — no business-rule or DB-structure changes, no SQL execution, no integration tests, no git state mutation.
**File touched:** `api/bootstrap.php:1` (global exception handler)

## 1) Why

Expected user errors (validation, duplicate, bad credentials) were becoming `500` on several admin and public endpoints because `ValidationException`/`ConflictException` were thrown without a local `catch` and the global handler in `api/bootstrap.php:24` mapped **every** `Throwable` to `500`.

**Inspected controllers (all):**
- `api/src/Controllers/Admin/CategoriesController.php:53` throws `ValidationException` (no local catch), `64` throws `ConflictException` (caught at `71` only for the integer-slug loop, not for the `53` path), `116` throws again.
- `api/src/Controllers/Admin/ProductsController.php:217` `throw ConflictException('Slug already exists.')` (store), `255` re-throws `ConflictException` from `PDOException 23000` (store), `301` same in `update` — all uncaught → `500`. `store:251`/`storeVariant:490`/`updateVariant:570` correctly catch `PDOException 23000` → `ConflictException`, but that `ConflictException` then bubbled.
- `api/src/Controllers/Admin/CustomersController.php:35`/`42` direct PDO queries without `PDOException` guard (read-only, low risk, no duplicate path).
- `api/src/Controllers/OrderController.php:63` and `api/src/Controllers/AuthController.php:46` **already** catch `ValidationException`/`ConflictException`/`InvalidCredentialsException` locally → `422`/`409`/`401` (correct).
- `api/src/Controllers/CartController.php:46` catches `ValidationException` locally.
- `api/src/Services/OrderService.php:186` etc. throw `ValidationException`/`ConflictException` — caught by callers above; `271` `PDOException` rollback+rethrow was unmapped.
- `api/src/Services/AuthService.php:74` etc. throw per-field errors — caught locally in `AuthController`.

Before fix: `Admin/CategoriesController::store` with duplicate name `POST /api/admin/categories` with empty name, or `ProductsController::store` with duplicate slug, returned `500 {"error":"Internal server error."}` with `X-Request-Id` logged to `api/storage/logs/app-error.log:50` as `error` (misclassified).

## 2) Fix

Centralized in `api/bootstrap.php:23` `set_exception_handler` (smallest safe scope — no controller business logic changed):

```php
if ($e instanceof ValidationException) → 422 {"error": $e->getMessage(), "errors": $e->errors()}
if ($e instanceof ConflictException)   → 409 {"error": $e->getMessage()}
if ($e instanceof InvalidCredentialsException) → 401 {"error": $e->getMessage()}
if ($e instanceof PDOException && (string)$e->getCode()==='23000') → 409 {"error":"A record already exists."} // generic, no SQL/table/column leak
else → 500 {"error":"Internal server error."} (debug ? + exception/file/line) + ErrorLogger::logThrowable() + X-Request-Id
```

- Keeps existing local `catch` blocks intact (they still produce identical JSON via `Controller::error:34` → `Response::error:38` → `Response::json:24`).
- Uncaught expected exceptions (categories/products admin) now map to `422`/`409` instead of `500`.
- `PDOException 23000` (unique/foreign-key violation — duplicate slug/SKU/size+color) never exposes `getMessage()` SQL; falls back to generic `A record already exists.` Controllers that already map `23000` to a specific message (`ProductsController:255`, `ColorsController:45`, `SizesController:48`) continue to return their specific `422`/`409` before the global 23000 path runs.
- Other `PDOException` (connection loss, syntax) stays `500` with redacted `ErrorLogger::sanitizeMessage:124` (`Bearer`, `password`, `secret`, etc. → `[redacted]`) and never logs bodies/headers/passwords/tokens (`ErrorLogger.php:28`).

No change to validation rules, auth, stock, or schema.

## 3) Consistent 4xx contract

| Exception | HTTP | JSON shape | Example |
|-----------|------|------------|---------|
| `ValidationException` (`api/src/Core/ValidationException.php:11`) | `422` | `{"error":"Validation failed.","errors":{"field":"msg"}}` | `POST /api/admin/categories` `{"name":""}` → `422 {"error":"Validation failed.","errors":{"name":"Name is required."}}` maps via bootstrap or via `Controller::error(422)` |
| `ConflictException` (`api/src/Core/ConflictException.php:11`) | `409` | `{"error":"message"}` | `POST /api/auth/register` duplicate email → `409 {"error":"Email already registered."}`; duplicate SKU → `409 {"error":"A variant SKU or size/color combination already exists."}` or generic `A record already exists.` for raw `PDOException 23000` |
| `InvalidCredentialsException` (`api/src/Core/InvalidCredentialsException.php:11`) | `401` | `{"error":"Invalid credentials."}` | `POST /api/auth/login` wrong password → `401` |
| `PDOException 23000` | `409` | `{"error":"A record already exists."}` | unhandled duplicate → `409` not `500`, no SQL in body |
| All other `Throwable` | `500` | `{"error":"Internal server error."}` (+ `exception/file/line` only when `APP_DEBUG=true`) + `X-Request-Id` header correlated to `api/storage/logs/app-error.log` | `ErrorException` from `set_error_handler` |

All 4xx bodies are `Content-Type: application/json; charset=utf-8` (same as `Response::json:26`). No stack trace, file path, SQL, secret, or credential ever leaves the server in non-debug mode. `DEBUG=true` adds `exception/file/line` only for local debugging — still sanitized via `ErrorLogger::sanitizeMessage:124`.

## 4) Static verification (no DB, no integration tests)

- **PHP lint:** `Get-ChildItem api -Recurse -Filter *.php | Where vendor/storage -notmatch | php -l` → `LINT DONE` (zero failures). `api/bootstrap.php:1` lints clean.
- **Static search — expected exceptions now all mapped:**

```
throw new ValidationException      → 13 sites (Admin/Categories 2, Products 0 but via Service, AdminAuthService 1, AuthService 7, OrderService 6) — all either locally caught (Auth/Cart/Order) or globally → 422
throw new ConflictException       → 7 sites (Admin/Categories 1, Admin/Products 3, AuthService 1, OrderService 4) — either locally caught or globally → 409
throw new InvalidCredentials      → 9 sites (AdminAuthService 1, AuthService 2, GoogleOAuthService 7) — locally caught (Auth/AdminAuth) or globally → 401
catch PDOException                → Admin/Products store/variant, Admin/Colors, Admin/Sizes, OrderService rollback, Newsletter/Wishlist duplicate guards (23000) — 23000 via Controller 422/409 or global 409 generic; other codes → 500 generic
```

After fix: `Select-String` shows **zero** `throw new ValidationException`/`ConflictException` without a 4xx path — every site either has a local `catch (ValidationException)`/`catch (ConflictException)` (e.g., `AuthController:46,68`, `OrderController:63`) **or** falls through to `bootstrap.php:24` global mapping (e.g., `Admin/CategoriesController.php:53`, `Admin/ProductsController.php:217`). No uncaught `PDOException 23000` remains as `500`.

- **Exposure check:** `Select-String api/src -Pattern "getMessage\(\)|getTrace|getFile"` shows only `ErrorLogger:73` (sanitized, logged server-side) and `bootstrap 40` (`debug` branch) — no 4xx path echoes `$e->getMessage()` for `PDOException` (global 23000 uses static string). `Response::error` never includes trace.

## 5) Remaining risks / not changed

- `OrderService:271` `catch (PDOException) { rollback; throw $e; }` rethrows non-23000 as `500` generic — correct, but caller cannot distinguish transient DB outage from logic error; retry logic stays client-side.
- `CustomersController:35` direct `PDO::prepare("SELECT COUNT(*) FROM users")` on list/show — no `PDOException` guard; on DB outage it becomes `500` (intended) via bootstrap, not `4xx`.
- `ContactController:67` swallows `RuntimeException` from `Auth::verify` (guest fallback) — intentionally not a 4xx.
- File `api/storage/logs/app-error.log` still gitignored (`storage/logs`), `X-Request-Id` correlation preserved.

## 6) Files

- **Edited:** `api/bootstrap.php:1` (added `use ConflictException/ValidationException/InvalidCredentialsException` + 4xx branches before 500 logger).
- **Docs:** this file; index updated at `docs/README.md:20`.
- **No** business-rule, DB, migration, `api/src` repository/service, or `frontend` changes. No `git add/commit/push` executed per task rule.
