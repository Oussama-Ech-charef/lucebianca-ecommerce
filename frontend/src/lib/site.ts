/**
 * Canonical site URL (phase 11 — SEO).
 *
 * Powers metadataBase, Open Graph URL resolution, sitemap.xml and robots.txt.
 * NEXT_PUBLIC_SITE_URL must point at the real domain before launch (see
 * .env.example); local dev falls back to http://localhost:3000.
 */
export const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

/**
 * Fallback social-share image (the logo, public/logo.png). Pages that have no
 * own image (home, shop, image-less products) reference this explicitly —
 * Next does not inherit the layout's openGraph/twitter images when a child
 * route defines its own openGraph object.
 */
export const OG_IMAGE = {
  url: `${SITE_URL}/logo.png`,
  width: 1536,
  height: 1024,
  alt: "Luce Bianca",
} as const;