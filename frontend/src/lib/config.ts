/**
 * Centralized frontend configuration — single source for all
 * production-sensitive placeholders (phase: production readiness audit).
 *
 * DO NOT invent real business values here. Every entry that is a placeholder
 * is marked `TODO (launch)` and must be replaced by the owner before the
 * production build. Missing production values fail safely (validation helpers
 * below) without changing the existing visual design.
 *
 * Sources:
 * - `NEXT_PUBLIC_SITE_URL` → site.ts / sitemap / robots / OG
 * - `NEXT_PUBLIC_API_URL`  → api.ts / admin.ts / storefront
 * - WhatsApp, social, shipping, sizes, legal/about copy → respective pages
 */

// ---------------------------------------------------------------------------
// Site & API — local vs production URLs
// ---------------------------------------------------------------------------

/**
 * TODO (launch): set `NEXT_PUBLIC_SITE_URL` to the real domain
 * (e.g. https://lucebianca.co) in `frontend/.env.production` or on Vercel.
 * Local dev fallback is `http://localhost:3000`.
 * Validation: must be https in production, no trailing slash, no localhost.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

/**
 * TODO (launch): set `NEXT_PUBLIC_API_URL` to the VPS PHP API origin
 * (e.g. https://api.lucebianca.co). Local dev fallback is `http://127.0.0.1:8090`.
 */
export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8090";

/**
 * Returns true when running against the real production domain (used to
 * make missing-config failures visible without inventing values).
 */
export function isProductionSite(url: string = SITE_URL): boolean {
  try {
    const u = new URL(url);
    return u.protocol === "https:" && u.hostname !== "localhost" && u.hostname !== "127.0.0.1";
  } catch {
    return false;
  }
}

/**
 * Safe check — warns in production if placeholders are still active.
 * Call from layout or provider once; it logs (never throws) in production
 * so the storefront still renders but the launch checklist is visible.
 */
export function warnIfPlaceholderConfig(): void {
  if (typeof window !== "undefined" || process.env.NODE_ENV !== "production") return;
  const issues: string[] = [];
  if (!isProductionSite(SITE_URL)) issues.push("NEXT_PUBLIC_SITE_URL still localhost — set to https://lucebianca.co");
  if (API_URL.includes("127.0.0.1") || API_URL.includes("localhost")) issues.push("NEXT_PUBLIC_API_URL still localhost — set to https://api.lucebianca.co");
  if (WHATSAPP_FALLBACK === "212612345678") issues.push("WHATSAPP_FALLBACK still placeholder — replace via admin settings or env");
  if (SOCIAL_LINKS.instagram === "#" || SOCIAL_LINKS.facebook === "#" || SOCIAL_LINKS.x === "#")
    issues.push("SOCIAL_LINKS still # — replace with real Luce Bianca URLs");
  if (issues.length) {
    console.warn("[Luce Bianca] Launch config placeholders active:\n" + issues.map((m) => " - " + m).join("\n"));
  }
}

// ---------------------------------------------------------------------------
// WhatsApp — single fallback number (live value is `settings.whatsapp_number`)
// ---------------------------------------------------------------------------
/**
 * TODO (launch): replace fallback via admin `settings.whatsapp_number`
 * (exposed as `whatsapp_number` in `GET /api/settings/public`). This
 * constant is only the compile-time fallback before settings load.
 */
export const WHATSAPP_FALLBACK = "212612345678";

// ---------------------------------------------------------------------------
// Social links — footer (phase: placeholder)
// ---------------------------------------------------------------------------
/**
 * TODO (launch): replace `#` placeholders with real Luce Bianca business URLs
 * (e.g. https://instagram.com/lucebianca.ma). Centralized here so the launch
 * checklist has one place to verify.
 */
export const SOCIAL_LINKS = {
  instagram: "#",
  facebook: "#",
  x: "#",
} as const;

// ---------------------------------------------------------------------------
// Shipping — placeholder values shown on /shipping (before settings wiring)
// ---------------------------------------------------------------------------
/**
 * TODO (launch): replace with live `settings.shipping_cost` /
 * `free_shipping_threshold` and `supported regions` from `GET /api/settings/public`.
 * `frontend/src/app/shipping/page.tsx` currently shows this static list with a
 * “REASONABLE PLACEHOLDERS” banner. See `docs/decisions/2026-09-22-admin-settings.md`
 * — shipping is validated but not yet charged in `OrderService`.
 */
export const SHIPPING_FALLBACK = [
  { region: "Casablanca", cost: "25 MAD", time: "1–2 business days" },
  { region: "Other Moroccan cities", cost: "45 MAD", time: "2–4 business days" },
  { region: "International", cost: "200 MAD", time: "7–14 business days" },
] as const;

export const FREE_SHIPPING_THRESHOLD_FALLBACK = "500 MAD";

// ---------------------------------------------------------------------------
// Size guide — placeholder measurements (owner must measure real samples)
// ---------------------------------------------------------------------------
/**
 * TODO (launch): replace with real garment measurements (flat chest/length/
 * shoulder/sleeve in cm). File: `frontend/src/app/size-guide/page.tsx`.
 * Keep the oversized-fit note; do not change branding/typography.
 */
export const SIZE_CHART_FALLBACK = [
  { size: "XS", chest: "94", length: "68", shoulder: "44", sleeve: "20" },
  { size: "S", chest: "102", length: "71", shoulder: "47", sleeve: "21" },
  { size: "M", chest: "110", length: "74", shoulder: "50", sleeve: "22" },
  { size: "L", chest: "118", length: "77", shoulder: "53", sleeve: "23" },
  { size: "XL", chest: "126", length: "80", shoulder: "56", sleeve: "24" },
  { size: "XXL", chest: "134", length: "83", shoulder: "59", sleeve: "25" },
] as const;

// ---------------------------------------------------------------------------
// Image / SEO fallbacks
// ---------------------------------------------------------------------------
/**
 * Fallback social-share image (public/logo.png). Used when a product has no
 * own image. `site.ts` already re-exports this as `OG_IMAGE`.
 */
export const OG_IMAGE_FALLBACK = {
  url: `${SITE_URL}/logo.png`,
  width: 1536,
  height: 1024,
  alt: "Luce Bianca",
} as const;

/**
 * TODO (launch): set real SEO defaults in `settings` (`seo_title` etc.) or
 * at least verify `frontend/src/app/layout.tsx` `metadata.title/description`
 * and `site.ts` `SITE_URL` before launch. The current defaults are generic
 * and intentionally not branded beyond “Casual Luxury Tees”.
 */

// ---------------------------------------------------------------------------
// Legal / about copy — placeholders for owner/legal review
// ---------------------------------------------------------------------------
/**
 * TODO (launch): have the exact copy in `about/page.tsx`, `returns/page.tsx`,
 * `terms/page.tsx`, `privacy/page.tsx`, `shipping/page.tsx`, `size-guide/page.tsx`
 * reviewed by the owner and a Moroccan legal professional before launch.
 * These pages currently contain draft/placeholder text that preserves the visual
 * design but is not final business information.
 */

// ---------------------------------------------------------------------------
// Admin cookie — security attributes (cannot be HttpOnly from JS)
// ---------------------------------------------------------------------------
/**
 * Admin gate cookie `lb_admin_session` is set via `document.cookie` in
 * `frontend/src/lib/admin.ts`. `HttpOnly` cannot be set from JavaScript
 * (requires `Set-Cookie` header from the PHP API). `Secure` is added only
 * when `location.protocol === "https:"` to keep localhost `http` working.
 * See `docs/decisions` for the launch checklist item to switch to a
 * `Secure` `HttpOnly` cookie issued by the API in production.
 */
export const ADMIN_COOKIE_NAME = "lb_admin_session";
