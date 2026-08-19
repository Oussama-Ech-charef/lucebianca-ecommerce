import type { MetadataRoute } from "next";

import { SITE_URL } from "@/lib/site";

/**
 * /robots.txt (phase 11 — SEO). Public storefront paths are crawlable; the
 * private/functional areas (admin, cart, checkout, order confirmation) are
 * excluded from indexing.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/admin", "/cart", "/checkout", "/order-confirmation"],
    },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}