# Phase 6 — Professional hero + shared header/footer + floating WhatsApp button

Date: 2026-08-17 (local-only notes, matches the docs convention so far)

Covers roadmap item 6: the hero section with Framer Motion, the shared
Header/Footer (every page, closes the phase-5 mobile-nav gap — spec 3.5
requires a hamburger menu on mobile), and the floating WhatsApp button
(spec 3.7.1). No backend involvement.

## A. Hero — video substitution decision

Spec 3.6 asks for an autoplay background **video** with a static-image
fallback. We have no licensed stock video asset, and fabricating a video
src that 404s is worse than an honest static hero. **Decision:** the hero
renders a static, CSS-composed background treatment now, and the code is
structured so a `<video>` (autoplay/muted/loop) can later replace it without
touching the parallax or foreground:

- The media layer lives in its own `HeroMedia()` component inside
  `src/components/hero.tsx`, placed exactly where the future video element
  goes — swap the component body, nothing else moves.
- Treatment is ink-black (`neutral-950`) + gold accent (`#C9A227`) + faint
  oversized serif fallback/ornament — the logo's strong black/white/gold
  identity, **no purple-to-blue gradient clichés** (3.6.2), fine corners
  (2px buttons), deliberate spacing. No third font: Playfair Display
  (headings) + Inter (body) only, per 3.6.1.1.
- **Parallax** via Framer Motion `useScroll` + `useTransform` on the section
  ref: background drifts slower (0→140px) than foreground text (0→-80px)
  with a fade; `useReducedMotion` disables the transform for reduced-motion
  users (accessibility).
- CTA: one clear "Shop now" → `/shop`; a few corner rules and the giant
  faint "Luce" watermark give the layer depth without an off-the-shelf look.
- `framer-motion` 13.1.0 added to dependencies (spec-named library).

## B. Shared Header (`src/components/header.tsx`, client)

Sticky top bar (white/95 + blur, fine 1px border) applied once in
`layout.tsx`, so `/`, `/shop`, `/product/[slug]` (and the not-found page)
all carry it.

- Wordmark "Luce Bianca" → `/`.
- **Nav links: only Shop** (`/shop`, a real route). About/Contact are
  deliberately OMITTED until those pages exist — linking to `/about|/contact`
  would 404, which is worse than a shorter nav. Reconsidered each phase the
  pages land.
- **Mobile hamburger (spec 3.5):** below `md`, the desktop nav is hidden and
  a hamburger opens a right-hand drawer (fixed overlay + semi-opaque
  backdrop). Verified to open, close via backdrop or ✕, and navigate (below).
- **Search icon (3.6.3 pattern):** opens an *inline search input* in the
  header (apple.com-style). Honest inert: typing shows a "Search is coming
  soon." state — no fake search over client-side-only data (no search API
  exists yet; wiring it is a later phase).
- **Cart icon (3.6.3 pattern):** opens a panel over the page with a real
  empty state — "Your cart is empty. Cart and checkout arrive in phase 7."
  No fake cart contents (no cart state exists).
- No icon library and no emoji: minimal inline SVG strokes (search, tote,
  hamburger, ✕) keep it tasteful and dependency-free.

## C. Footer (`src/components/footer.tsx`)

Modest per spec: wordmark + "Casual Luxury" tagline, the Shop link, and a
copyright line. Consistent with the typography; every page gets it via the
layout.

## D. Floating WhatsApp button (`src/components/whatsapp-button.tsx`)

- Fixed bottom-right, `z-50`, visible on every page while scrolling.
- WhatsApp brand green `#25D366`, white icon, prefers a real (non-fake)
  link: `https://wa.me/<number>?text=<welcome message>` (pre-filled,
  URL-encoded).
- **⚠ Placeholder number flagged:** `212612345678` (format
  `212XXXXXXXXX`). The real store WhatsApp number must replace it before
  launch — noted in the code's placeholder constant and here.
- z-50 sits below the header (z-40) and below transient overlays
  (drawer/cart z-55–60); mobile QA confirmed it never covers the header.

## E. Integration (phase-5 pages untouched otherwise)

- `layout.tsx` now wraps children as `<Header /><div>{children}</div><Footer
 /><WhatsAppButton />` inside the existing `min-h-full flex-col` body, so
  the footer sits at the bottom and the header is sticky — pages keep their
  own `<main>`.
- Small integration cleanup, not a rework: removed the now-redundant
  in-page "Luce Bianca" wordmark from `shop/page.tsx` (`ShopHeading`) and
  replaced the `/product` breadcrumb ("Luce Bianca / Shop") with a single
  "← Back to shop" link to avoid duplicating the header wordmark. Filters,
  grid, gallery, variant selector, JSON-LD, MAD pricing all unchanged.

## F. Mobile-first verification (spec 3.5) — real evidence

Ran a Playwright-core harness driving **headless Edge** (system browser) at a
`375×667` mobile viewport against the live dev server, plus a 1280×800
desktop pass. **30/30 checks passed**; page errors: none. Key results at
mobile width:

- Hero headline visible, no horizontal scroll (scrollWidth ≤ innerWidth).
- Hamburger visible, desktop nav hidden; **drawer opens, closes via
  backdrop, and the Shop link navigates** to `/shop` (real click).
- Search icon opens the inline input; typing yields the honest
  "coming soon" state; cart icon opens the empty panel and closes.
- WhatsApp button visible, bottom-right corner, its top edge is **below**
  the header (no overlap while scrolling); still visible on `/shop` and
  `/product`.
- `/shop` renders the product card grid + the `39,99 MAD` price; `/product`
  renders the title + M/L variant chips; neither overflows horizontally;
  footer + WhatsApp present on both.
- Desktop pass: header nav visible, wordmark links home, footer visible,
  hero CTA href `/shop`, `/shop` filters + MAD price intact, `/product`
  JSON-LD still `priceCurrency: "MAD"`, no page errors.

## G. Verification

- `npm run lint` → 0 errors; `npm run build` → clean (same route table:
  `/` static, `/shop` + `/product/[slug]` dynamic).
- Live reload of `/`, `/shop`, `/product/casual-luxury-tee` confirms
  header/footer/WhatsApp present without breaking phase-5 features.

## Remaining / notes for later phases

- Real video hero asset (drop into `HeroMedia()`), real store WhatsApp
  number, About/Contact pages (then add their nav links), live search
  endpoint → wire the inline search, cart state → populate the cart panel.
- `playwright-core` added as a devDependency (drives system Edge, no browser
  download) strictly to keep the mobile-first QA harness reproducible.

## Logo integration + silver/chrome accent (late phase-6 follow-up, two passes)

The real **logo.png** (1536×1024, transparent, monochrome silver/chrome "LB"
monogram; pixel sampling shows the full tonal range lum 0 → 255, ~50% of opaque
pixels are ink < 200) replaced the text wordmark in the header and footer and
the hero watermark; accent color switched from gold to silver/chrome.

### Pass 1 (initial swap) — what fell short

- **Logo too small to be a brand mark:** header `h-9` (~36px) read as a small
  icon squeezed next to nav/icon controls; footer `h-8` shrank further.
- **Watermark had no presence:** the logo mark at `opacity-[0.07]` and a mere
  256px (mobile) / 448px (desktop) wide sat in the bottom-right corner — a
  decorative afterthought next to the old giant "Luce" text (`text-[16rem]` /
  `text-[28rem]`) it replaced.
- **Flat, washed-out palette:** the two-tone accent (`#E7E7E5` + `#9A9A97`)
  sampled from the logo's average was a neutral flat grey with no tonal range,
  no highlight/shadow contrast, and no sheen — it read as "grey", not
  "polished chrome", and drained the gold version's visual energy.

### Pass 2 (this revision) — corrected sizes + metallic palette

- **File location:** the PNG was found at `api/public/logo.png` (not
  `frontend/public/`), so it was copied to `frontend/public/logo.png` and is
  served as `/logo.png` via `next/image`. (Unchanged from pass 1.)
- **Favicon:** `app/icon.png` is the installed Next version's supported
  convention → logo copied there and scaffold `src/app/favicon.ico` deleted.
  (Unchanged from pass 1.)
- **Header:** logo now `h-12` (48px, rendered 72×48) on mobile and `h-14`
  (56px, rendered 84×56) on desktop — a real primary brand mark, 3:2 ratio
  preserved via `w-auto`. Header bar grows to `h-20` on desktop (`md:h-20`) so
  the larger logo gets breathing room; link keeps a touch-friendly hit area
  (`py-1` + `pr-3/4`). Verified to fit inside the header without overlap.
- **Footer:** logo `h-12` mobile / `h-14` desktop (was `h-8`) — no longer
  shrinking relative to the header.
- **Hero watermark:** the faint text became the logo mark rendered as a large
  **cropped/zoomed presence** (`object-cover` fills a 26rem×20rem box on
  mobile, 72rem×48rem on desktop — 90% of the hero's width on desktop),
  anchored bottom-right and bleeding off-canvas like the old "Luce" text,
  `opacity-15`. On the ink-black hero the mark reads as a genuine chrome
  texture: pixel-readback luminance is **4.2× the background on mobile** and
  **2.8× on desktop** (bg lum 10 → 42 / 27), vs the pass-1 faint spot. It
  remains the `HeroMedia()` swap layer for the future video.
- **Chrome palette — gold → silver (2nd pass):** all `#C9A227` removed (grep
  on `frontend/src/` + the served CSS bundle confirms zero gold-ish hex left).
  A dedicated `chrome` scale in `@theme` (`globals.css`), cool **blue-grey**,
  not neutral grey, with the full tonal range the first pass lacked:
  - `chrome-50/100` `#F6F7F8`/`#EDF0F2` — bright near-white highlight.
  - `chrome-200` `#D7DCE1`, `chrome-300` `#BAC2CB`, `chrome-400` `#9BA6B1` —
    light-to-mid sheen, all cool-toned.
  - `chrome-500` `#7C8995` — distinct cool mid-tone (the "not flat grey" core).
  - `chrome-600`→`chrome-950` `#5D6A76`→`#111418` — dark shadows to near-black.
  - **Gradients for the metallic sheen** (flat one-hex accent was the core
    problem, so sheen is applied as `bg-clip-text`/hover gradients):
    - Hero eyebrow: `from-white via-chrome-200 to-chrome-400`, text transparent.
    - Hero italic headline: `from-white via-chrome-200 to-chrome-500`,
      `bg-gradient-to-br` (light falling on polished metal).
    - "Shop now" hover: `bg-gradient-to-b from-chrome-200 to-chrome-600`.
    - "SCROLL" hint: `chrome-400` label + `chrome-600` rule — confirmed cool
      grey, no amber trace (any remaining gold was stale compiled CSS, which a
      full rebuild flushed).
  - Black/white remains the foundation (spec 3.6.2's strong-contrast intent):
    hero stays ink-black `neutral-950` with white headline; chrome **punctuates**
    (eyebrow, italic line, CTA hover, scroll hint, watermark) exactly where gold
    used to — it never takes over large surfaces. WhatsApp green `#25D366` is
    the official brand button color (spec 3.7.1), not an accent — unchanged.
- **Contrast check (header logo on white):** rendered element pixel readback
  at desktop = ~19% of the element area is ink (lum < 200) with darkest ≈ 96;
  the monogram's near-black strokes (PNG min lum 0) carry legibility on white.
- **Verification:** `npm run lint` → 0; `npm run build` → clean (same route
  table: `/` + `/icon.png` static, `/shop` + `/product/[slug]` dynamic).
  Playwright-core / headless Edge harness → **all checks passed**:
  - **Mobile 375×667:** header logo 48px tall, fits the 65px header without
    overlap; **no horizontal scroll** (scrollWidth = innerWidth = 375);
    watermark box 375×320 visible, anchored bottom-right, spans the full lower
    hero (no scroll impact — hero is `overflow-hidden`); eyebrow/headline
    gradient text computed; 0 page errors.
  - **Desktop 1280×800:** header logo 56px tall in the 81px header; watermark
    box 1152×768 = 90% of hero width, `opacity 0.15`, `object-fit: cover`;
    eyebrow + headline `bg-clip-text` gradients and CTA hover gradient resolve
    to the chrome values; "SCROLL" color `rgb(155,166,177)` (`#9BA6B1`, cool);
    footer logo 56px; **zero `#C9A227`/gold-ish hex in the served CSS bundle**;
    0 page errors.