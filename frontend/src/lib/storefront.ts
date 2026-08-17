/**
 * Storefront data layer — the two server-rendered pages (T3 /shop and
 * T3 /product/[slug]) talk to the PHP API only through these helpers.
 *
 * Next 16 does not cache `fetch` by default, so every request hits the live
 * API (a paused product disappears from the storefront immediately).
 */

import { api } from "@/lib/api";
import type {
  CategoriesResponse,
  Paginated,
  ProductDetail,
  ShopProduct,
} from "@/lib/types";

export type ProductFilters = {
  page?: string;
  category?: string;
  size?: string;
  color?: string;
  min_price?: string;
  max_price?: string;
};

export function buildProductQuery(filters: ProductFilters): string {
  const params = new URLSearchParams();
  const set = (key: string, value: string | undefined) => {
    if (value) {
      params.set(key, value);
    }
  };

  set("page", filters.page);
  set("category", filters.category);
  set("size", filters.size);
  set("color", filters.color);
  set("min_price", filters.min_price);
  set("max_price", filters.max_price);

  const query = params.toString();
  return query === "" ? "" : `?${query}`;
}

// Moroccan Dirham — locale fr-MA renders as "39,99 MAD", the standard
// convention for the storefront (payments settle via CMI/Payzone in MAD).
const formatter = new Intl.NumberFormat("fr-MA", {
  style: "currency",
  currency: "MAD",
});

export function formatPrice(value: string | number): string {
  return formatter.format(Number(value));
}

/** GET /api/products — paginated, filtered, only is_active = 1. */
export function fetchProducts(filters: ProductFilters) {
  return api<Paginated<ShopProduct>>(
    `/api/products${buildProductQuery(filters)}`,
  );
}

/**
 * GET /api/products/{slug} — full detail with variants and images.
 * Throws ApiError(404) when the product is paused or missing.
 */
export function fetchProductDetail(slug: string) {
  return api<{ data: ProductDetail }>(`/api/products/${encodeURIComponent(slug)}`);
}

/** GET /api/categories — drives the shop's category filter. */
export function fetchCategories() {
  return api<CategoriesResponse>("/api/categories");
}