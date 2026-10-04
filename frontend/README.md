# Luce Bianca — Storefront (Next.js 16 + React 19 + TypeScript + Tailwind)

Premium custom t-shirts storefront (App Router, server-rendered for SEO) talking to the PHP REST API in `../api`.

## Requirements

- Node.js **>=20.9.0** (Next.js 16.3.1 requirement; see `engines` + `.nvmrc`). Node 18 will fail to build.
- npm 10+
- Running PHP API (default `http://127.0.0.1:8090`, see `../api/.env.example`)

## Setup

```bash
cd frontend
cp .env.example .env.local   # set NEXT_PUBLIC_SITE_URL + NEXT_PUBLIC_API_URL
npm install
npm run dev                  # http://localhost:3000
```

### Environment variables (`frontend/.env.example` → `.env.local`, never committed)

| Variable | Required | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SITE_URL` | prod yes | Canonical origin (`http://localhost:3000` dev, `https://lucebianca.co` prod). Used for metadata/sitemap/OG/email links. |
| `NEXT_PUBLIC_API_URL` | prod yes | PHP API origin (`http://127.0.0.1:8090` dev, `https://api.lucebianca.co` prod). |
| `NEXT_PUBLIC_GOOGLE_CLIENT_ID` | no | Enables the Google sign-in button; unset hides it. |

`NEXT_PUBLIC_*` values are public and embedded in the build. Placeholders that must be replaced before launch are centralized in `src/lib/config.ts` (`TODO (launch)`): WhatsApp fallback, social links, shipping/size-guide/legal copy.

## Scripts

```bash
npm run dev    # Next dev server (allowedDevOrigins includes 127.0.0.1 for harness)
npm run build  # production build (fails on type/ESLint errors)
npm start      # serve the production build
npm run lint   # eslint (flat config, next/core-web-vitals + typescript)
npx tsc --noEmit  # TypeScript check (strict)
```

## Project layout

- `src/app/` — routes: `/`, `/shop`, `/product/[slug]`, `/cart`, `/checkout`, `/payment/[id]` (card 3-D Secure return + polling), `/order-confirmation?id=&token=`, `/account`, `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`, `/wishlist`, `/newsletter/unsubscribe`, info pages (`about/shipping/size-guide/returns/terms/privacy/contact`), `/admin/*`.
- `src/lib/` — `api.ts` (fetch + HttpOnly cookie `credentials:include`), `storefront.ts` (products/orders/payments/newsletter), `customer-auth.ts` (JWT in memory/localStorage, refresh via HttpOnly cookie), `admin.ts`, `config.ts` (placeholders), `whatsapp.ts`.
- `src/components/` — header (cart/menu/search dialogs with focus trap + Escape + focus return), hero, footer, newsletter form, WhatsApp button.

## Key behaviors

- **Payments:** COD/WhatsApp/card all create `payment_status=pending` (never auto-paid). Card orders redirect via auto-submitted HTML form POST to CMI, then poll `GET /api/payments/status/{id}` on `/payment/[id]`; failures show retry and restore stock server-side. CMI returns the customer browser to `/payment/{id}?token=...&pay=success|failure` (auto-poll on arrival); the server-to-server `POST /api/payments/callback` stays the authoritative state change and is never customer-facing.
- **Totals:** `total = subtotal + shipping` computed server-side from `settings.shipping_cost` / `free_shipping_threshold`. Checkout/cart/confirmation/WhatsApp/admin/email render the same breakdown.
- **Privacy:** guest orders use `?token=lookup_token` (or `X-Order-Token`); private pages carry `noindex` + robots.txt disallow.
- **Errors:** route-level `src/app/error.tsx` + root `src/app/global-error.tsx` boundaries render a generic safe message with a support reference id; stack traces, tokens, raw API errors, and internal URLs never reach the UI (details only in development).
- **Accessibility:** visible `focus-visible` rings everywhere, radiogroup star rating with arrow keys, dialogs with `role=dialog aria-modal` + focus trap + scroll lock.

## Deployment (Vercel)

1. Set env vars (`NEXT_PUBLIC_SITE_URL=https://lucebianca.co`, `NEXT_PUBLIC_API_URL=https://api.lucebianca.co`, optional Google ID).
2. `npm run build && npm start` locally to verify, then deploy the `frontend/` directory.
3. Ensure the PHP API allows the origin via `CORS_ALLOWED_ORIGINS` and `TRUSTED_PROXIES` (see `../api/.env.example`).
4. Replace launch placeholders (WhatsApp number via `/admin/settings`, social links, shipping regions, legal copy) — the app warns in production via `warnIfPlaceholderConfig()`.

## Verification

```bash
npm run lint
npx tsc --noEmit
npm run build
```

API integration tests live in `../api/tests/` (isolated `lucebianca_test` DB only).
