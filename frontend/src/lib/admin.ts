/**
 * Admin data + session layer (phase 9).
 *
 * The admin panel is a deliberately separate session from the customer
 * storefront (spec section 4: admins are a fully separate table): tokens are
 * stored under their own localStorage key and mirrored into a dedicated cookie
 * (`lb_admin_session`) so `src/proxy.ts` can gate /admin/* server-side without
 * a flash of protected content. Neither key is reused by the customer cart
 * (`lucebianca:cart:v1`) or any future customer auth storage.
 *
 * The refresh_token is persisted for parity with the customer flow, but the
 * `/api/admin/auth/refresh` endpoint is a later phase — access calls use the
 * short-lived JWT, and a 401 on the orders API clears the session.
 */

import { api } from "@/lib/api";
import type {
  AdminAuthPayload,
  AdminOrder,
  OrderDetail,
  OrderStatus,
  Paginated,
  PaymentStatus,
} from "@/lib/types";

const SESSION_KEY = "lucebianca:admin:session:v1";
const SESSION_COOKIE = "lb_admin_session";

export type AdminOrderFilters = {
  page?: number;
  per_page?: number;
  status?: OrderStatus | "";
};

function readSession(): AdminAuthPayload | null {
  if (typeof window === "undefined") {
    return null;
  }
  try {
    const raw = window.localStorage.getItem(SESSION_KEY);
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw) as unknown;
    if (
      typeof parsed !== "object" ||
      parsed === null ||
      typeof (parsed as { token?: unknown }).token !== "string"
    ) {
      return null;
    }
    return parsed as AdminAuthPayload;
  } catch {
    return null;
  }
}

function setSessionCookie(token: string): void {
  document.cookie = `${SESSION_COOKIE}=${encodeURIComponent(token)}; path=/; SameSite=Lax`;
}

function clearSessionCookie(): void {
  document.cookie = `${SESSION_COOKIE}=; path=/; SameSite=Lax; Max-Age=0`;
}

/** POST /api/admin/auth/login — email/password admin login. */
export function loginAdmin(email: string, password: string) {
  return api<AdminAuthPayload>("/api/admin/auth/login", {
    method: "POST",
    body: { email, password },
  });
}

/** Persists a fresh admin session (localStorage + gate cookie). */
export function saveAdminSession(payload: AdminAuthPayload): void {
  window.localStorage.setItem(SESSION_KEY, JSON.stringify(payload));
  setSessionCookie(payload.token);
}

/** Current admin session from localStorage, or null when absent/corrupt. */
export function getAdminSession(): AdminAuthPayload | null {
  return readSession();
}

/** Clears the admin session (localStorage + gate cookie) — logout. */
export function clearAdminSession(): void {
  try {
    window.localStorage.removeItem(SESSION_KEY);
  } catch {
    // Storage blocked — the cookie clear below still drops the gate.
  }
  clearSessionCookie();
}

/**
 * GET /api/admin/orders — paginated order list with optional status filter.
 * Requires the admin access token.
 */
export function fetchAdminOrders(filters: AdminOrderFilters, token: string) {
  const params = new URLSearchParams();
  params.set("page", String(filters.page ?? 1));
  params.set("per_page", String(filters.per_page ?? 12));
  if (filters.status) {
    params.set("status", filters.status);
  }
  return api<Paginated<AdminOrder>>(`/api/admin/orders?${params.toString()}`, {
    token,
  });
}

/**
 * PUT /api/admin/orders/{id} — change an order's status / payment_status.
 * Returns the updated order with full items (same shape as GET /api/orders/{id}).
 */
export function updateAdminOrder(
  id: number,
  fields: { status?: OrderStatus; payment_status?: PaymentStatus },
  token: string,
) {
  return api<{ data: OrderDetail }>(`/api/admin/orders/${id}`, {
    method: "PUT",
    body: fields,
    token,
  });
}