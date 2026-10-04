# Runbook — HTTPS & security headers

Current reality (do not assume more): the repo contains **no** reverse-proxy
config, no TLS certificates and no Docker/systemd files. In dev the
storefront runs on `http://localhost:3000` (Next) and the API on plain
`http://127.0.0.1:8090` (`php -S`, or Apache via `api/public/.htaccess`).
Everything below is what *you* must configure on your production host —
values in `<ANGLE BRACKETS>` are placeholders to replace.

## 1. TLS termination & HTTPS enforcement

Pick ONE pattern and keep origins consistent:

- **A. nginx in front of both apps** — proxy `/` to Next (`127.0.0.1:3000`)
  and `/api/` to PHP (FPM socket, or `127.0.0.1:8090`). TLS ends at nginx.
- **B. Apache with mod_php/FPM** for the API (the shipped `.htaccess`
  assumes Apache rewrites to `public/index.php`) + separate Next host.

Either way, redirect all HTTP→HTTPS and send HSTS once HTTPS is stable:

```nginx
server {
    listen 80;
    server_name <DOMAIN>;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl http2;
    server_name <DOMAIN>;

    # HSTS — start conservative (e.g. 300), raise to 31536000 when proven.
    add_header Strict-Transport-Security "max-age=300" always;
    include snippets/<your-cert-config>;
}
```

## 2. Headers that fit this stack

Apply at the proxy (or later in `frontend/next.config.ts` `headers()` — a
code change, out of scope here). Test with `curl -sI https://<DOMAIN>/`.

| Header | Value | Why |
|---|---|---|
| `Strict-Transport-Security` | `max-age=…` | Force HTTPS after rollout |
| `X-Content-Type-Options` | `nosniff` | JSON API + static assets |
| `X-Frame-Options` | `DENY` | Storefront is never framed |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Don't leak paths cross-site |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Site uses none of these |

CSP for the **Next.js frontend**: Next injects inline bootstrap scripts, so
a naive CSP breaks it. Roll out as **report-only first**; when enforcing you
will need nonces/hashes. Minimum directives that match this codebase:
`default-src 'self'; img-src 'self' https://res.cloudinary.com data:`
(product images are Cloudinary-only per `next.config.ts`);
`frame-ancestors 'none'; object-src 'none'`.
The **API serves JSON only** — CSP adds little there; don't let header
experiments break CORS preflights (see §3).

## 3. Cookies & tokens — what this app actually does

- Customer + admin JWTs live in **localStorage** by design (separate keys,
  see phase-9/13 decision docs); they are sent as `Authorization: Bearer`.
  Consequence: XSS is the real threat → CSP matters more than cookie flags.
- One cookie exists today: `lb_admin_session` set by
  `frontend/src/lib/admin.ts` as `SameSite=Lax` plus `Secure` when
  `location.protocol === "https:"` (`frontend/src/lib/admin.ts:60` via
  `frontend/src/lib/config.ts:ADMIN_COOKIE_NAME`). Remaining gap: it is still
  **not `HttpOnly`** (JS `document.cookie` cannot set `HttpOnly`; production
  should have the PHP API issue `Set-Cookie: …; HttpOnly; Secure; SameSite=Lax`
  on `POST /api/admin/auth/login` — see `docs/decisions/2026-09-24-auth-audit.md` and
  `frontend/src/lib/config.ts` note).
- CSRF is stateless (`X-CSRF-Token` header, HMAC token) — no session cookies
  involved, nothing extra to secure there.

CORS checklist (all values live in `api/.env`):
- `CORS_ALLOWED_ORIGINS` must list the **https** storefront origin(s),
  e.g. `https://<DOMAIN>`. Remove localhost entries in production.
- `SITE_URL` (API) and `NEXT_PUBLIC_SITE_URL` (frontend) must both be the
  canonical `https://<DOMAIN>` — verification emails and OG/sitemap URLs
  are built from them.
- If Google Sign-In stays enabled, add the https origin in the Google
  Cloud console authorized-JavaScript-origins list.
- Card payments are implemented: `CMI_CALLBACK_URL` is the server-to-server
  endpoint (`POST /api/payments/callback`, no auth/CSRF by design — CMI
  signs with HMAC) and must stay publicly reachable over HTTPS. It must
  NEVER be customer-facing: `CMI_RETURN_URL` (frontend `/payment` base,
  defaults to `SITE_URL/payment`) is where CMI redirects customer browsers
  (`/payment/{id}?token=...&pay=success|failure`, see `PaymentService`).

## 4. Verification checklist

```bash
# 1. HTTP redirects to HTTPS
curl -sI http://<DOMAIN>/ | head -n 3            # expect 301 → https

# 2. Certificate valid + matches domain
openssl s_client -connect <DOMAIN>:443 -servername <DOMAIN> </dev/null 2>/dev/null | openssl x509 -noout -subject -dates

# 3. Headers present on frontend AND api paths
curl -sI https://<DOMAIN>/        | grep -iE "strict-transport|x-content|referrer"
curl -sI https://<DOMAIN>/api/health | grep -i x-content

# 4. CORS preflight still allowed after header changes
curl -s -X OPTIONS https://<API-HOST>/api/cart \
  -H "Origin: https://<DOMAIN>" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type, x-csrf-token" -o /dev/null -D -
# expect: Access-Control-Allow-Origin matches AND Allow-Headers includes X-CSRF-Token

# 5. Real mutation works end-to-end (browser): login → submit a review or
#    newsletter signup; check DevTools Network for no CORS/CSP violations.
```

5 failing while 4 passes usually means a proxy stripped the custom header —
re-check §2 additions against the preflight response.
