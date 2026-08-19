import type { MetadataRoute } from "next";

import { SITE_URL } from "@/lib/site";
import { fetchProducts } from "@/lib/storefront";

/**
 * /sitemap.xml (phase 11 — SEO). Static entries for the storefront root and
 * shop, plus one entry per active product at /product/{slug}, sourced live
 * from the public products API. The API caps per_page, so we paginate
 * through every page rather than assuming the first page is the whole
 * catalog. If the API is unreachable we still emit the static entries.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [
    {
      url: `${SITE_URL}/`,
      lastModified: new Date(),
      changeFrequency: "weekly",
      priority: 1,
    },
    {
      url: `${SITE_URL}/shop`,
      lastModified: new Date(),
      changeFrequency: "daily",
      priority: 0.8,
    },
  ];

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