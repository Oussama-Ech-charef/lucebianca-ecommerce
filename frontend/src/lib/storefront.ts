/**
 * Storefront data layer — the two server-rendered pages (T3 /shop and
 * T3 /product/[slug]) talk to the PHP API only through these helpers.
 *
 * Next 16 does not cache `fetch` by default, so every request hits the live
 * API (a paused product disappears from the storefront immediately).
 */

import { api } from "@/lib/api";
import type {
  CartValidationResponse,
  CategoriesResponse,
  OrderDetail,
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
 *
 * ISR (spec 3.2): the page is revalidated hourly, so this fetch is cached for
 * 3600s server-side to match the route's `revalidate`; a paused product or a
 * price change surfaces within an hour instead of requiring a rebuild.
 */
export function fetchProductDetail(slug: string) {
  return api<{ data: ProductDetail }>(
    `/api/products/${encodeURIComponent(slug)}`,
    { next: { revalidate: 3600 } },
  );
}

/** GET /api/categories — drives the shop's category filter. */
export function fetchCategories() {
  return api<CategoriesResponse>("/api/categories");
}

/**
 * GET /api/orders/{id} — a single order with its lines, for the order
 * confirmation page. Throws ApiError(404) when the order does not exist.
 */
export function fetchOrder(id: number | string) {
  return api<{ data: OrderDetail }>(`/api/orders/${encodeURIComponent(String(id))}`);
}

/**
 * POST /api/cart — validates each cart line against live stock and prices.
 * Called by the checkout form on load and again at submit time.
 */
export function validateCart(
  items: { variant_id: number; quantity: number; unit_price?: number }[],
) {
  return api<CartValidationResponse>("/api/cart", {
    method: "POST",
    body: { items },
  });
}

/**
 * POST /api/orders — places an order (COD or WhatsApp payment method).
 * Returns 201 with the created order; throws ApiError(409) on a stock
 * conflict and ApiError(422) on invalid input.
 *
 * A customer token is optional: when present (logged-in checkout) the API
 * attributes the order to the customer (orders.user_id) so it appears on
 * /account; a guest checkout sends no token and stays anonymous.
 */
export function placeOrder(
  payload: {
    customer_name: string;
    phone: string;
    shipping_address: string;
    payment_method: "cod" | "whatsapp";
    items: { variant_id: number; quantity: number }[];
  },
  token?: string,
) {
  return api<{ data: OrderDetail }>("/api/orders", {
    method: "POST",
    body: payload,
    token,
  });
}

/**
 * POST /api/contact — submits the /contact form message. Returns 201 with the
 * stored row's id; throws ApiError(422) with per-field errors on bad input.
 * Includes honeypot field (website) for bot detection — real users never fill it.
 */
export function submitContactMessage(payload: {
  name: string;
  email: string;
  message: string;
  website: string;
}) {
  return api<{ data: { id: number } }>("/api/contact", {
    method: "POST",
    body: payload,
  });
}