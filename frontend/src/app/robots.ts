import type { MetadataRoute } from "next";

import { SITE_URL } from "@/lib/site";

/**
 * /robots.txt (phase 11 — SEO). Public storefront paths are crawlable; the
 * private/functional areas (admin, auth, account, cart, checkout, payment,
 * order confirmation, wishlist, newsletter actions) are excluded. Per-page
 * `robots: noindex` metadata covers the same set for crawlers that fetch
 * robots.txt less strictly.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: [
        "/admin",
        "/account",
        "/login",
        "/register",
        "/forgot-password",
        "/reset-password",
        "/verify-email",
        "/wishlist",
        "/cart",
        "/checkout",
        "/payment",
        "/order-confirmation",
        "/newsletter",
      ],
    },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}