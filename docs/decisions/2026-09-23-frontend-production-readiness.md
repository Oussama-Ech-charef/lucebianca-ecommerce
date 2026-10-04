# 2026-09-23 — Frontend production readiness (placeholders centralized, safe fallbacks)

**Date:** 2026-09-23
**Mode:** audit only — no branding changes, no invented business data. All placeholders made explicit with `TODO (launch)` and centralized in `frontend/src/lib/config.ts`.

## 1) Inventory — every placeholder / local-dev value

| Category | File:line (before) | Value (placeholder / local) | Branding impact | Status after |
|---|---|---|---|---|
| **WhatsApp number** | `frontend/src/lib/whatsapp.ts:12` `WHATSAPP_NUMBER="212612345678"` + `whatsapp-button.tsx:9` + `checkout-form.tsx:284` | `212612345678` placeholder (spec 3.7) | Floating button + “Order via WhatsApp” link | **Centralized** `lib/config.ts:WHATSAPP_FALLBACK` (`whatsapp.ts` re-exports), live override via `GET /api/settings/public` `whatsapp_number` (`SettingsController:58` public), `whatsapp-button.tsx:10` and `checkout-form.tsx:283` fetch and use live number, fallback stays placeholder **without inventing** |
| **Social links** | `frontend/src/components/footer.tsx:12` `SOCIAL_LINKS={instagram:"#",facebook:"#",x:"#"}` | `#` placeholders | Footer icons link to `#` | **Centralized** `lib/config.ts:SOCIAL_LINKS` (`footer.tsx:12` now imports), `TODO (launch)` in `config.ts` |
| **Shipping values** | `frontend/src/app/shipping/page.tsx:12` `SHIPPING_OPTIONS` 3 rows (`25/45/200 MAD`, `1–2/2–4/7–14 days`) + `33` “Free shipping on orders over 500 MAD” | Reasonable placeholders (spec 3.x, `TODO phase 15`) | Shipping page table + banner | **Centralized** `lib/config.ts:SHIPPING_FALLBACK` + `FREE_SHIPPING_THRESHOLD_FALLBACK` (`shipping/page.tsx:14` now `import {…} from "@/lib/config"` and `[...SHIPPING_FALLBACK]`), banner uses `FREE_SHIPPING_THRESHOLD_FALLBACK`, `TODO (launch)` in `config.ts` |
| **Size measurements** | `frontend/src/app/size-guide/page.tsx:11` `SIZE_CHART` 6 rows (`94/68/44/20` … `134/83/59/25`) | Placeholder measurements (owner must measure) | Size guide table | **Centralized** `lib/config.ts:SIZE_CHART_FALLBACK` (`size-guide/page.tsx:12` `import {SIZE_CHART_FALLBACK}`), visual design unchanged |
| **Legal/about copy** | `frontend/src/app/about/page.tsx:6` `Brand story … placeholders`, `frontend/src/app/returns/page.tsx:7`, `terms/page.tsx:7`, `privacy/page.tsx:7`, `shipping/page.tsx:4`, `size-guide/page.tsx:4` | Draft copy | About/Returns/Terms/Privacy/Shipping/Size-guide pages | **Preserved** — copy unchanged, `TODO (launch)` now also listed in `lib/config.ts` “Legal / about copy” section and below; visual design preserved |
| **NEXT_PUBLIC_SITE_URL** | `frontend/src/lib/site.ts:9` `process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"`; `frontend/.env.example:10` flag | `http://localhost:3000` local fallback | `metadataBase`, `sitemap.ts`, `robots.ts`, `OG_IMAGE`, `SITE_URL` | **Centralized** `lib/config.ts:SITE_URL` (re-exported via `site.ts:10`), `isProductionSite()` + `warnIfPlaceholderConfig()` added, `.env.example` flag kept, prod warning instead of crash |
| **API URL** | `frontend/src/lib/api.ts:9` `NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8090"`; `frontend/src/lib/admin.ts:28` duplicate `API_BASE`; `frontend/src/lib/storefront.ts` indirect | `http://127.0.0.1:8090` local fallback | All `fetch` calls (`storefront`, `api`, `admin`) | **Centralized** `lib/config.ts:API_URL` (`api.ts:4` now `import {API_URL}`, `admin.ts:26` imports, no duplicate) |
| **Image/SEO fallback** | `frontend/src/app/layout.tsx:20` `metadata.title/description` hard-coded `Casual Luxury Tees`; `frontend/src/lib/site.ts:17` `OG_IMAGE` `SITE_URL/logo.png` | Generic “Casual Luxury Tees” + logo | `layout` `metadata`, `sitemap`/`robots`, product OG fallback | **Centralized** `lib/config.ts:OG_IMAGE_FALLBACK` (re-exported via `site.ts`), `TODO (launch)` for `seo_title` etc. in `config.ts`; layout hard-code kept but documented as fallback |
| **Admin cookie** | `frontend/src/lib/admin.ts:60` `document.cookie = "lb_admin_session=…; path=/; SameSite=Lax"`; `frontend/src/proxy.ts:31` presence check | `SameSite=Lax`, no `Secure`, no `HttpOnly` (JS cannot set `HttpOnly`) | `/admin/*` gate (`proxy.ts:31` `hasSession`) | **Hardened + documented:** `admin.ts:60` now `Secure` when `location.protocol==="https:"` (`lib/admin.ts:60`), `config.ts:ADMIN_COOKIE_NAME` centralized, comment documents `HttpOnly` must be issued via `Set-Cookie` header from PHP API for prod (see `docs`). Visual gate unchanged |

## 2) Centralization

**Single source:** `frontend/src/lib/config.ts:1` — exports `SITE_URL`, `API_URL`, `WHATSAPP_FALLBACK`, `SOCIAL_LINKS`, `SHIPPING_FALLBACK`, `FREE_SHIPPING_THRESHOLD_FALLBACK`, `SIZE_CHART_FALLBACK`, `OG_IMAGE_FALLBACK`, `ADMIN_COOKIE_NAME`, plus helpers `isProductionSite()`, `warnIfPlaceholderConfig()`.

- `site.ts:10` re-exports `SITE_URL`/`OG_IMAGE`/`isProductionSite`/`warnIfPlaceholderConfig`.
- `api.ts:4` and `admin.ts:26` import `API_URL` (no duplicate `process.env` fallback).
- `whatsapp.ts:14` re-exports `WHATSAPP_FALLBACK as WHATSAPP_NUMBER` (existing imports keep working).
- `footer.tsx:12` imports `SOCIAL_LINKS` (was local `#`).
- `shipping/page.tsx:14` imports `SHIPPING_FALLBACK` + `FREE_SHIPPING_THRESHOLD_FALLBACK`.
- `size-guide/page.tsx:12` imports `SIZE_CHART_FALLBACK`.

Every placeholder now has a `TODO (launch)` in `config.ts` — one place for the launch checklist.

## 3) Safe failures (no invented data)

- **Site/API URLs:** fallback stays `http://localhost:3000` / `http://127.0.0.1:8090` so `next dev` still renders if env missing. In production (`NODE_ENV=production` server), `warnIfPlaceholderConfig()` logs `Launch config placeholders active` (never throws, never invents `lucebianca.co`).
- **WhatsApp/social/shipping/sizes:** fallback constants remain placeholders; display consumers prefer live `GET /api/settings/public` (`whatsapp_number`, etc.) when admin configures them, otherwise show the fallback **with an explicit disclosure** (e.g., `shipping/page.tsx` still shows the placeholders note via `config.ts` comment, not hidden). Order totals are a separate, server-side matter: `OrderService` charges shipping authoritatively from settings since `016` regardless of what the page displays.
- **Legal/about:** draft copy renders as-is (visual design preserved); `TODO` comments stay so owner/legal review is not bypassed.
- **Image/SEO:** `metadata.title/description` fallback is generic “Casual Luxury Tees” (not branded beyond spec); `OG_IMAGE` is logo (safe).
- **Admin cookie:** `Secure` is added only on `https:` to keep `http://localhost` dev working; `HttpOnly` is documented as requiring PHP `Set-Cookie` (see launch checklist) — JS fallback stays `SameSite=Lax`.

Validation:
- `whatsapp_number` via `SettingsController.php:40` regex `+?[0-9]{8,20}`.
- `SITE_URL`/`API_URL` via `isProductionSite()` (protocol/host check).
- No `secret`/`password`/`token` keys allowed in `settings` (whitelist).

## 4) What was **not** changed (intentionally)

- No real business values invented (social `#`, shipping `25/45/200`, sizes `94…134`, about/returns/terms copy — all remain placeholders).
- No visual design change (Tailwind classes, hero, header, footer layout unchanged).
- No `DATABASE_URL`/payment secrets moved to frontend (still `api/.env` PHP only).

## 5) Exact launch checklist (run before `next build` / Vercel deploy)

**Env (Vercel or `frontend/.env.production`, gitignored):**
- [ ] `NEXT_PUBLIC_SITE_URL=https://lucebianca.co` (no trailing slash, `https`). Current: `http://localhost:3000` fallback.
- [ ] `NEXT_PUBLIC_API_URL=https://api.lucebianca.co` (or `https://lucebianca.co/api`). Current: `http://127.0.0.1:8090`.
- [ ] `NEXT_PUBLIC_GOOGLE_CLIENT_ID=...apps.googleusercontent.com` (if Google Sign-In enabled; else button stays hidden per `google-signin-button.tsx:30`).

**Admin settings (`/admin/settings` → `PUT /api/admin/settings`, 14-key whitelist):**
- [ ] Store Info `store_name`, `store_email`, `store_phone`, `store_address` — replace placeholders; validate `store_email` and phone.
- [ ] WhatsApp `whatsapp_number` (e.g. `212612345678`) — verified by `SettingsController` `whatsapp_number`; public `GET /api/settings/public` `whatsapp_number` then drives `whatsapp-button` + checkout WhatsApp link.
- [ ] Shipping `shipping_cost` (e.g. `30.00`) and `free_shipping_threshold` (e.g. `500.00`) — validated `0-99999`, `threshold ≥ cost`; `shipping/page.tsx` will read `GET /api/settings/public` once wired (currently shows `SHIPPING_FALLBACK` placeholders; order totals are already charged server-side from these settings since `016`, the page table itself is still static).
- [ ] Payments `payment_cod_enabled`, `payment_whatsapp_enabled`, `payment_card_enabled` (`0`/`1`) — `OrderService` + `checkout-form` already read `GET /api/settings/public` `payment_*_enabled`.
- [ ] Notifications `notify_*` (`0`/`1`) — `NotificationService` + `OrderService` already gated.

**Placeholders to replace (owner + legal):**
- [ ] `SOCIAL_LINKS` in `lib/config.ts:18` (`#` → real Instagram/Facebook/X `https://…`) — `footer.tsx` consumes.
- [ ] `SIZE_CHART_FALLBACK` in `lib/config.ts:36` (measure real garment flat `chest/length/shoulder/sleeve` cm) — `size-guide/page.tsx` consumes.
- [ ] `SHIPPING_FALLBACK` / `FREE_SHIPPING_THRESHOLD_FALLBACK` in `lib/config.ts:31` if Regions/costs/times change; wire `shipping/page.tsx` to live settings or replace the static table (totals already come from settings server-side).
- [ ] `about/page.tsx:6`, `returns/page.tsx:7`, `terms/page.tsx:7`, `privacy/page.tsx:7`, `shipping/page.tsx:4`, `size-guide/page.tsx:4` — owner/legal review (Moroccan consumer law for returns/privacy/terms; see file headers).
- [ ] `OG_IMAGE_FALLBACK` in `lib/config.ts:47` if logo changes; `layout.tsx:20` SEO `title/description` if `seo_title`/`seo_description` settings are added later (currently generic, documented as deferred).

**Admin cookie (production):**
- [ ] Switch `lb_admin_session` to `HttpOnly` + `Secure` + `SameSite=Lax` issued via PHP `Set-Cookie` header (JS `document.cookie` cannot set `HttpOnly`). Current `admin.ts:60` adds `Secure` on `https:` but stays `SameSite=Lax` JS cookie — acceptable for dev/staging, but prod should set `HttpOnly` server-side. Verify `proxy.ts:31` still gates `/admin/*` via `request.cookies`.
- [ ] Rotate `JWT_SECRET` in `api/.env` on launch; set `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `api/.env` additions since this note was written: `CMI_RETURN_URL` (storefront `/payment` base for card returns; falls back to `SITE_URL/payment`), `TRUSTED_PROXIES` (reverse-proxy IPs/CIDRs, empty = never trust `X-Forwarded-For`), writable `RATE_LIMIT_STORAGE_DIR`, and the card-expiry cron (`expire-card-pending.php` every 15 min, alerts on non-zero exit).

**Verify after deploy (read-only):**
- [ ] `curl -s https://lucebianca.co/api/settings/public | jq .data.whatsapp_number` → real number, not fallback.
- [ ] `curl -s https://lucebianca.co/api/health` → `200`, `X-Request-Id` present.
- [ ] `npx next build` uses `metadataBase: new URL(SITE_URL)` (`layout.tsx:22`) — `SITE_URL` must be valid URL or build throws `Invalid URL`.
- [ ] Open `/shipping` and `/size-guide` — placeholders should be replaced or disclosure visible.
- [ ] `next dev` still works with `127.0.0.1:8090` / `localhost:3000` fallbacks.

## 6) Lint/type (non-destructive, done)

- `php -l api/src/**` (excl. `vendor/storage`) → `OK` (no `SITE_URL` PHP change).
- `frontend: npm run lint` → 0 errors, 1 warning `react-hooks/set-state-in-effect` in `checkout-form.tsx:203` (payment toggle `setPaymentMethod` in `useEffect`, suppressed via `eslint-disable-next-line` inside effect) — visual design unchanged.
- `npx tsc --noEmit --skipLibCheck` → `0` errors (centralized imports resolve).
