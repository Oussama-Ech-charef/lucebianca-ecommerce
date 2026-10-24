/**
 * Storefront data layer — the two server-rendered pages (T3 /shop and
 * T3 /product/[slug]) talk to the PHP API only through these helpers.
 *
 * Next 16 does not cache `fetch` by default, so every request hits the live
 * API (a paused product disappears from the storefront immediately).
 */

import { api, fetchCsrfToken } from "@/lib/api";
import type {
  CartValidationResponse,
  CategoriesResponse,
  CouponPreview,
  OrderDetail,
  Paginated,
  ProductDetail,
  ShopProduct,
} from "@/lib/types";

export type ProductFilters = {
  page?: string;
  per_page?: string;
  search?: string;
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
  set("search", filters.search?.trim());
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
 * Always fresh (no-store): the detail route is force-dynamic so an admin
 * deactivating a product takes effect on the very next direct visit instead
 * of after an ISR window. Catalog/listing caching elsewhere is unaffected.
 */
export function fetchProductDetail(slug: string) {
  return api<{ data: ProductDetail }>(
    `/api/products/${encodeURIComponent(slug)}`,
    { cache: "no-store" },
  );
}

/** GET /api/categories — drives the shop's category filter. */
export function fetchCategories() {
  return api<CategoriesResponse>("/api/categories");
}

/** GET /api/products/new-arrivals — newest active products for homepage. */
export function fetchNewArrivals(limit = 8) {
  return api<{ data: ShopProduct[] }>(`/api/products/new-arrivals?limit=${limit}`);
}

/** GET /api/products/special-offers — active products with valid sale_price. */
export function fetchSpecialOffers(limit = 8) {
  return api<{ data: ShopProduct[] }>(`/api/products/special-offers?limit=${limit}`);
}

/**
 * GET /api/orders/{id} — a single order with its lines, for the order
 * confirmation page. Guest lookup requires the single-purpose
 * `lookup_token` (query `?token=` or header `X-Order-Token`); owned
 * orders (user_id) also allow authenticated ownership via Bearer token.
 * Throws ApiError(404) when not found or not authorized (to hide existence).
 */
export function fetchOrder(id: number | string, lookupToken?: string, authToken?: string) {
  const qs = lookupToken ? `?token=${encodeURIComponent(lookupToken)}` : "";
  return api<{ data: OrderDetail }>(`/api/orders/${encodeURIComponent(String(id))}${qs}`, {
    ...(authToken ? { token: authToken } : {}),
  });
}

/**
 * POST /api/cart — validates each cart line against live stock and prices.
 * Called by the checkout form on load and again at submit time.
 */
export async function validateCart(
  items: { variant_id: number; quantity: number; unit_price?: number }[],
) {
  const csrfToken = await fetchCsrfToken();
  return api<CartValidationResponse>("/api/cart", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: { items },
  });
}

/**
 * POST /api/coupons/validate — previews a coupon against the cart.
 *
 * The server recomputes live prices/stock and returns the authoritative
 * subtotal, discount, shipping and total. Throws ApiError(422) for an
 * invalid/expired code and ApiError(409) when a cart line is unavailable.
 */
export async function previewCoupon(
  code: string,
  items: { variant_id: number; quantity: number }[],
) {
  const csrfToken = await fetchCsrfToken();
  return api<{ data: CouponPreview }>("/api/coupons/validate", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: { code, items },
  });
}

/**
 * POST /api/orders — places an order (COD, WhatsApp or card payment method).
 * Returns 201 with the created order; throws ApiError(409) on a stock
 * conflict and ApiError(422) on invalid input.
 *
 * A customer token is optional: when present (logged-in checkout) the API
 * attributes the order to the customer (orders.user_id) so it appears on
 * /account; a guest checkout sends no token and stays anonymous.
 *
 * payment_status is ALWAYS pending at creation (COD/WhatsApp included —
 * paid only via card callback success or admin confirmation). total_amount
 * is authoritative server-side (subtotal + shipping from settings).
 */
export async function placeOrder(
  payload: {
    customer_name: string;
    phone: string;
    email: string;
    shipping_address: string;
    city: string;
    postal_code: string;
    payment_method: "cod" | "whatsapp" | "card";
    items: { variant_id: number; quantity: number }[];
    coupon_code?: string;
  },
  token?: string,
) {
  const csrfToken = await fetchCsrfToken();
  return api<{ data: OrderDetail }>("/api/orders", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: payload,
    token,
  });
}

/**
 * POST /api/orders/{id}/cancel — cancels a pending/processing order and
 * restores stock. Requires ownership or lookup token (same as fetchOrder).
 */
export async function cancelOrder(
  id: number | string,
  lookupToken?: string,
  authToken?: string,
) {
  const csrfToken = await fetchCsrfToken();
  return api<{ data: OrderDetail }>(
    `/api/orders/${encodeURIComponent(String(id))}/cancel`,
    {
      method: "POST",
      headers: { "X-CSRF-Token": csrfToken },
      body: lookupToken ? { lookup_token: lookupToken } : {},
      ...(authToken ? { token: authToken } : {}),
    },
  );
}

export type PaymentConfig = {
  card_enabled: boolean;
  card_configured: boolean;
  card_available: boolean;
};

/** GET /api/payments/config — whether card payment can be offered (no secrets). */
export function fetchPaymentConfig() {
  return api<{ data: PaymentConfig }>("/api/payments/config", {
    cache: "no-store",
  });
}

export type PaymentInitiation = {
  gateway_url: string;
  form_fields: Record<string, string>;
};

/**
 * POST /api/payments/initiate — returns the CMI gateway URL + signed form
 * fields. The caller must auto-submit them as a real HTML form POST (never
 * fetch/XHR — the customer completes 3-D Secure on CMI's hosted page).
 */
export async function initiateCardPayment(
  orderId: number,
  lookupToken?: string,
  authToken?: string,
  email?: string,
) {
  const csrfToken = await fetchCsrfToken();
  return api<{ data: PaymentInitiation }>("/api/payments/initiate", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: {
      order_id: orderId,
      ...(lookupToken ? { lookup_token: lookupToken } : {}),
      ...(email ? { email } : {}),
    },
    ...(authToken ? { token: authToken } : {}),
  });
}

export type PaymentStatusResponse = {
  order_id: number;
  payment_status: "pending" | "paid" | "failed";
  payment_method: string;
  total_amount: string;
  subtotal_amount: string;
  shipping_cost: string;
  status: string;
};

/** GET /api/payments/status/{orderId} — poll after CMI redirect/callback. */
export function fetchPaymentStatus(
  orderId: number | string,
  lookupToken?: string,
  authToken?: string,
) {
  const qs = lookupToken ? `?token=${encodeURIComponent(lookupToken)}` : "";
  return api<{ data: PaymentStatusResponse }>(
    `/api/payments/status/${encodeURIComponent(String(orderId))}${qs}`,
    { ...(authToken ? { token: authToken } : {}), cache: "no-store" },
  );
}

/**
 * POST /api/contact — submits the /contact form message. Returns 201 with the
 * stored row's id; throws ApiError(422) with per-field errors on bad input.
 * Includes honeypot field (website) for bot detection — real users never fill it.
 * When a customer token is provided, the server derives identity from the
 * verified token and ignores client-supplied name/email.
 */
export async function submitContactMessage(
  payload: {
    name: string;
    email: string;
    message: string;
    website: string;
  },
  token?: string,
) {
  const csrfToken = await fetchCsrfToken();
  return api<{ data: { id: number } }>("/api/contact", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: payload,
    ...(token ? { token } : {}),
  });
}

/**
 * GET /api/settings/public — safe public settings for storefront (WhatsApp,
 * payment toggles, store info). No auth, never includes secrets.
 */
export type PublicSettings = {
  store_name?: string;
  store_email?: string;
  store_phone?: string;
  store_address?: string;
  whatsapp_number?: string;
  shipping_cost?: string;
  free_shipping_threshold?: string;
  payment_cod_enabled?: string;
  payment_whatsapp_enabled?: string;
  payment_card_enabled?: string;
};

export function fetchPublicSettings() {
  return api<{ data: PublicSettings }>("/api/settings/public", { cache: "no-store" });
}

/**
 * POST /api/newsletter/subscribe — joins the newsletter list. CSRF-guarded
 * (and rate-limited with /contact), so a fresh token is fetched per call and
 * sent as X-CSRF-Token. Resolves with subscribed=false ("Already
 * subscribed.") for repeat signups instead of failing; throws ApiError on
 * 422 (invalid email) or 429 (rate limit).
 */
export async function subscribeToNewsletter(
  email: string,
): Promise<{
  data: {
    subscribed: boolean;
    message: string;
  };
}> {
  const csrfToken = await fetchCsrfToken();
  return api("/api/newsletter/subscribe", {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: { email },
  });
}