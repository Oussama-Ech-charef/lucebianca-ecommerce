/**
 * Wishlist API layer — GET/POST/DELETE /api/wishlist (customer JWT required).
 *
 * POST and DELETE are guarded by CsrfMiddleware, so every mutation first
 * fetches a fresh token via GET /api/csrf-token and sends it back in the
 * `X-CSRF-Token` header (the header CsrfProtection reads from requests).
 */
import { api, fetchCsrfToken } from "@/lib/api";
import type { WishlistItem, WishlistMutation } from "@/lib/types";

/** GET /api/wishlist — the customer's saved products, newest first. */
export function fetchWishlist(token: string) {
  return api<{ data: WishlistItem[] }>("/api/wishlist", { token });
}

/** POST /api/wishlist — add a product (`added` false = already saved). */
export async function addToWishlist(
  productId: number,
  token: string,
): Promise<WishlistMutation> {
  const csrfToken = await fetchCsrfToken();
  const response = await api<{ data: WishlistMutation }>("/api/wishlist", {
    method: "POST",
    token,
    headers: { "X-CSRF-Token": csrfToken },
    body: { product_id: productId },
  });
  return response.data;
}

/** DELETE /api/wishlist/{productId} — remove a product (204 No Content). */
export async function removeFromWishlist(
  productId: number,
  token: string,
): Promise<void> {
  const csrfToken = await fetchCsrfToken();
  await api<void>(`/api/wishlist/${productId}`, {
    method: "DELETE",
    token,
    headers: { "X-CSRF-Token": csrfToken },
  });
}
