import type { MetadataRoute } from "next";

import { SITE_URL } from "@/lib/site";
import { fetchProducts } from "@/lib/storefront";

/**
 * /sitemap.xml (phase 11 — SEO, extended phase 14). Static entries for the
 * storefront root, shop and the info/legal pages, plus one entry per active
 * product at /product/{slug}, sourced live from the public products API. The
 * API caps per_page, so we paginate through every page rather than assuming
 * the first page is the whole catalog. If the API is unreachable we still
 * emit the static entries.
 */
const STATIC_PAGES: { path: string; changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"]; priority: number }[] = [
  { path: "/", changeFrequency: "weekly", priority: 1 },
  { path: "/shop", changeFrequency: "daily", priority: 0.8 },
  { path: "/about", changeFrequency: "yearly", priority: 0.3 },
  { path: "/contact", changeFrequency: "yearly", priority: 0.3 },
  { path: "/size-guide", changeFrequency: "yearly", priority: 0.3 },
  { path: "/shipping", changeFrequency: "monthly", priority: 0.4 },
  { path: "/returns", changeFrequency: "yearly", priority: 0.3 },
  { path: "/terms", changeFrequency: "yearly", priority: 0.2 },
  { path: "/privacy", changeFrequency: "yearly", priority: 0.2 },
];

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = STATIC_PAGES.map((page) => ({
    url: `${SITE_URL}${page.path}`,
    lastModified: new Date(),
    changeFrequency: page.changeFrequency,
    priority: page.priority,
  }));

  try {
    const seen = new Set<number>();
    let page = 1;

    while (true) {
      const result = await fetchProducts({ page: String(page) });
      for (const product of result.data) {
        if (seen.has(product.id)) {
          continue;
        }
        seen.add(product.id);
        entries.push({
          url: `${SITE_URL}/product/${product.slug}`,
          lastModified: new Date(product.updated_at),
          changeFrequency: "weekly",
          priority: 0.7,
        });
      }
      if (page >= result.meta.pages) {
        break;
      }
      page += 1;
    }
  } catch {
    // API unreachable — keep the static entries rather than failing the route.
  }

  return entries;
}