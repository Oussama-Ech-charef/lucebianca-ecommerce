# Phase 14 — Info & legal pages

Date: 2026-08-20

Roadmap item **3.6-ish static/info pages** (spec section 6): `/about`,
`/contact`, `/size-guide`, `/shipping`, `/returns`, `/terms`, `/privacy`.
Scoped strictly to these static/semi-static storefront pages — admin, payment
and customer-auth (phase 13) work untouched.

## A. Backend — contact endpoint (was a 501 stub)

A `ContactController` already existed with `POST /api/contact` routed
(`routes.php:61`) but its `store()` was a `notImplemented` stub. Built:

- `App\Repositories\ContactMessageRepository` — prepared-statement insert into
  `contact_messages` (name, email, message, `is_read = 0`); the table already
  existed in the phase-2 schema (its read/unread flag powers the future
  `/admin/messages`).
- `ContactController::store` now validates server-side (`name` required +
  min 2, `email` required + format, `message` required + min 10) via the
  shared `Validator`, returns **422 with per-field errors** on bad input and
  **201 `{data:{id}}`** on success. Rate limiting + honeypot/captcha remain
  deferred to the security phase (spec 3.1.3), as the stub's docblock noted.

## B. Frontend pages

All pages are storefront-branded (font-serif `h1`, neutral palette,
`max-w-3xl` content column, `<main>` flex-1 inside the existing
`StorefrontShell`). `/contact` is a client view behind a server wrapper (for
`metadata`); the other six are static server components.

| Page | Content | Real vs placeholder |
| --- | --- | --- |
| `/about` | Brand story, "Casual Luxury" philosophy, vision (spec 6) | **Draft copy** — real founding narrative needs the owner's input |
| `/contact` | Working form (name/email/message) → `POST /api/contact`, green success + red error alerts matching the site's alert language | **Real** — end-to-end verified (submits and lands in `contact_messages`) |
| `/size-guide` | Centimeter chart XS–XXL (chest/length/shoulder/sleeve) + oversized-fit guidance + measuring tips (spec 6) | **Placeholder measurements** — `SIZE_CHART` constant flagged `TODO (owner)` in `page.tsx`; must be replaced with real flat-garment measurements |
| `/shipping` | Regions, costs, delivery times, free-shipping-over-500-MAD callout | **Placeholder values** — `SHIPPING_OPTIONS` constant flagged `TODO (phase 15)`: values should come from `/admin/settings` once built (spec section 7) |
| `/returns` | 14-day return/exchange policy, refund window, COD refund note | **Generic draft** — visible "review by a legal professional" note |
| `/terms` | 9 sections (agreement, pricing in MAD, orders, payments, delivery, returns, IP, liability, contact) | **Generic draft** — visible "review by a legal professional" note |
| `/privacy` | 7 sections (what we collect, use, storage, retention, rights, cookies, changes) | **Generic draft** — visible "review by a legal professional" note |

The three legal pages carry a discreet "template draft — review by a legal
professional before launch" note under the heading (per the task's explicit
flag requirement).

## C. Footer + sitemap

- `footer.tsx` rebuilt from the single "Shop" link into two grouped columns —
  **Shop** (Shop) and **Help & info** (About, Contact, Size guide, Shipping,
  Returns, Terms, Privacy) — staying responsive (single column on mobile,
  grouped row on `sm+`).
- `sitemap.ts` (phase 11) now emits the 9 static storefront URLs via a
  `STATIC_PAGES` table (root + shop + 7 new pages) with sensible
  `changeFrequency`/`priority` (info/legal pages yearly / 0.2–0.4), then still
  appends every active product URL live from the API.

## D. Verification

- `php -l` clean on both new PHP files.
- API smoke: valid submission → 201 + row in `contact_messages` (`is_read=0`);
  invalid (empty name, malformed email, short message) → 422 with per-field
  errors.
- `npm run lint` 0 errors; `npm run build` clean; all 7 routes appear as
  `○ (Static)` (23 static pages total).
- Playwright-core + system Edge @ 375×667: **39/39 PASS** — every page renders
  HTTP 200, has no horizontal overflow (`scrollWidth ≤ clientWidth`), shows
  its expected content, and exposes its footer link; the contact form reaches
  the success state end-to-end (row confirmed in the DB), and an invalid
  submission shows the red error alert. No unexpected console errors.
- Test messages removed after verification.

## Notes / follow-ups

- Shipping values and size measurements are the two consciously-placeholder
  pieces (each flagged with a code TODO): shipping must move to
  `/admin/settings` (phase 15); size measurements need the real garment specs.
- The `/about` story and the three legal pages are drafts — the owner must
  replace them with real content and legal review before launch.
- Anti-spam on `/api/contact` (rate limiting, honeypot) is still deferred to
  the security phase.