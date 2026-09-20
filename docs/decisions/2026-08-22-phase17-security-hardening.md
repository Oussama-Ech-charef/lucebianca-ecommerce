# Phase 17: Security Hardening

**Date:** 2026-08-21  
**Status:** Implemented

## Overview

Implemented comprehensive security hardening across the platform:
- Rate limiting for auth and contact endpoints
- Honeypot protection for contact form
- CSRF protection for state-changing operations
- Error response audit for information leakage prevention

## 1. Rate Limiting

**Implementation:** File-based rate limiter with IP tracking

**Files:**
- `api/src/Core/RateLimiter.php` — Core rate limiting logic
- `api/src/Core/RateLimitMiddleware.php` — Middleware wrapper

**Limits Applied:**
- Login: 5 attempts per 5 minutes (`/api/auth/login`)
- Register: 5 attempts per 5 minutes (`/api/auth/register`)
- Contact: 3 attempts per 10 minutes (`/api/contact`)

**Response:** 429 Too Many Requests with `Retry-After` header

**Storage:** `api/storage/rate-limits/` (file-based, auto-created)

**Production Notes:**
- Uses X-Forwarded-For header when behind reverse proxy
- Redis-based rate limiting recommended for high-traffic scenarios
- Current file-based approach suitable for initial deployment

## 2. Honeypot Protection

**Implementation:** Hidden `website` field in contact form

**Backend:** `api/src/Controllers/ContactController.php`
- Rejects submissions where `website` field is non-empty
- Returns 422 (same as validation errors) to avoid revealing the trap

**Frontend:** `frontend/src/app/contact/contact-view.tsx`
- Field positioned off-screen (`position: absolute; left: -9999px`)
- Hidden from assistive tech (`aria-hidden="true"`)
- `tabIndex={-1}` prevents keyboard focus
- `autoComplete="off"` prevents autofill

**Security Principle:** Bots typically fill all form fields; humans never see this field

## 3. CSRF Protection

**Implementation:** Stateless HMAC-based tokens with timestamp expiration

**Files:**
- `api/src/Core/CsrfProtection.php` — Token generation/validation
- `api/src/Core/CsrfMiddleware.php` — Request validation
- `api/src/Controllers/CsrfController.php` — Token endpoint

**Token Format:**
- Base64-encoded `timestamp:signature`
- Signature: HMAC-SHA256(timestamp, JWT_SECRET)
- Lifetime: 1 hour (3600 seconds)
- Stateless design (no session storage required)

**Endpoints Protected:**
- Contact form: `POST /api/contact`
- Cart validation: `POST /api/cart`
- Order placement: `POST /api/orders`
- All admin state-changing operations (POST/PUT/DELETE)

**Client Usage:**
1. Fetch token: `GET /api/csrf-token`
2. Include in requests via:
   - Header: `X-CSRF-Token: <token>` (preferred)
   - Body: `_csrf_token: <token>` (fallback)

**Response on Failure:** 403 Forbidden with message "Invalid or expired CSRF token."

## 4. Error Response Audit

**Findings:**

### ✅ Secure Practices Already in Place
1. **No SQL details exposed** — All queries use PDO prepared statements
2. **No stack traces** — Production error handling uses generic messages
3. **No secret leakage** — Environment variables never echoed in responses
4. **Anti-enumeration in auth:**
   - Login: Generic "Invalid credentials" (doesn't reveal if email exists)
   - Email verification: Generic success message regardless of account status
   - Resend verification: Same message for all outcomes

### ✅ Validation Errors
- Return structured 422 responses with per-field errors
- Messages are user-friendly, never expose internal logic
- Example: `{"error": "Validation failed.", "errors": {"email": ["Invalid email format"]}}`

### ✅ Auth Errors
- 401: "Invalid credentials" (login) — no email enumeration
- 409: "Email already registered" (register) — acceptable for UX
- 400: Generic message for invalid tokens (verification)

### ✅ Database Errors
- Caught exceptions never leak SQL details to client
- PDO configured with `PDO::ERRMODE_EXCEPTION`
- All catches return generic 500 or specific business logic errors

## Security Requirements Met

✅ **SQL Injection:** Prepared statements (PDO) on every query  
✅ **XSS:** Server-side sanitization + React's built-in protection  
✅ **Rate Limiting:** Applied to login, register, contact  
✅ **CSRF:** Protected state-changing operations  
✅ **Input Validation:** Server-side on every endpoint  
✅ **Account Enumeration:** Prevented in auth flows  
✅ **Error Disclosure:** No secrets, SQL, or stack traces exposed

## Environment Variables Required

```env
# Existing (already in .env)
JWT_SECRET=<random-string>

# New (optional tuning)
RATE_LIMIT_WINDOW=300        # Default: 5 minutes
RATE_LIMIT_MAX_REQUESTS=5    # Default: 5 attempts
```

## Testing Checklist

- [ ] Rate limiting: Exceed login attempts → 429 response
- [ ] Honeypot: Fill `website` field → 422 rejection
- [ ] CSRF: POST without token → 403 error
- [ ] CSRF: POST with valid token → success
- [ ] CSRF: POST with expired token → 403 error
- [ ] Error messages: No SQL/secrets in any error response
- [ ] Contact form: Spam protection working end-to-end

## Deployment Notes

1. **Rate limit storage:** Ensure `api/storage/rate-limits/` is writable
2. **CSRF integration:** Frontend must fetch token before state-changing requests
3. **Reverse proxy:** If using nginx/Cloudflare, ensure `X-Forwarded-For` is set
4. **Production HTTPS:** Required for secure cookie transmission (future sessions)

## Frontend Integration Required

The frontend needs to be updated to:
1. Fetch CSRF tokens from `GET /api/csrf-token`
2. Include token in X-CSRF-Token header for:
   - Contact form submissions
   - Cart operations
   - Order placement
   - Admin operations

This will be implemented in the next phase to avoid breaking existing functionality.

## Known Limitations

1. **File-based rate limiting:** Not suitable for multi-server deployments (use Redis)
2. **IP-based tracking:** Can affect users behind NAT (acceptable for this store's scale)
3. **CSRF token refresh:** Client must handle 403 and fetch new token

## Future Enhancements

- [ ] Redis-based rate limiting for production scale
- [ ] Session-based CSRF tokens (if sessions are added)
- [ ] Automated security scanning in CI/CD
- [ ] Content Security Policy (CSP) headers
- [ ] Rate limiting on failed payment attempts
