"use client";

import { useSyncExternalStore } from "react";

import { api } from "@/lib/api";
import type {
  CustomerAuthPayload,
  CustomerOrder,
  CustomerUser,
  Paginated,
} from "@/lib/types";

/**
 * Customer auth + session layer (phase 13).
 *
 * Session security: the long-lived refresh token is now ALSO issued as an
 * HttpOnly/Secure/SameSite=Lax cookie (`lb_customer_refresh`, see
 * api/src/Controllers/AuthController.php) — JS cannot read it, so XSS cannot
 * steal it. New sessions persist ONLY the short-lived access JWT + profile
 * in localStorage; refresh/logout prefer the cookie (body token is a
 * backward-compat fallback for sessions created before the cookie flow).
 * Access calls use the short-lived JWT; account data is fetched fresh.
 */

const SESSION_KEY = "lucebianca:customer:session:v1";

/** POST /api/auth/register — creates a customer account. */
export function registerCustomer(
  name: string,
  email: string,
  password: string,
  phone?: string,
) {
  return api<CustomerAuthPayload>("/api/auth/register", {
    method: "POST",
    body: { name, email, password, phone: phone || undefined },
  });
}

/** POST /api/auth/login — email/password login. */
export function loginCustomer(email: string, password: string) {
  return api<CustomerAuthPayload>("/api/auth/login", {
    method: "POST",
    body: { email, password },
  });
}

/**
 * POST /api/auth/google — registers or logs in via a Google Identity
 * Services id_token (verified server-side; account is created or linked by
 * verified email).
 */
export function googleLogin(idToken: string) {
  return api<CustomerAuthPayload>("/api/auth/google", {
    method: "POST",
    body: { id_token: idToken },
  });
}

/** POST /api/auth/logout — revokes the refresh token (idempotent, cookie-preferred). */
export function logoutCustomer(refreshToken?: string) {
  return api<void>("/api/auth/logout", {
    method: "POST",
    // Omit the body when no legacy token exists — the server falls back to
    // the HttpOnly cookie. Sending an empty body would 422.
    ...(refreshToken ? { body: { refresh_token: refreshToken } } : {}),
  });
}

/** POST /api/auth/refresh — rotates via cookie when no legacy token is stored. */
export function refreshCustomerSession(refreshToken?: string) {
  return api<CustomerAuthPayload>("/api/auth/refresh", {
    method: "POST",
    ...(refreshToken ? { body: { refresh_token: refreshToken } } : { body: {} }),
  });
}

/** GET /api/account — the authenticated customer's profile. */
export function fetchCustomerAccount(token: string) {
  return api<{ data: CustomerUser }>("/api/account", { token });
}

/**
 * GET /api/auth/verify-email — confirms a customer's email via the one-time
 * token from the emailed link. Returns { message } on success.
 */
export function verifyCustomerEmail(token: string) {
  return api<{ message: string }>(
    `/api/auth/verify-email?token=${encodeURIComponent(token)}`,
  );
}

/**
 * POST /api/auth/resend-verification — re-sends the verification email. The
 * response is identical whether the email is unknown/verified/unverified.
 */
export function resendCustomerVerification(email: string) {
  return api<{ message: string }>("/api/auth/resend-verification", {
    method: "POST",
    body: { email },
  });
}

/**
 * POST /api/auth/forgot-password — requests a one-time reset link.
 * Anti-enumeration: identical message whether the email exists or not.
 */
export function requestPasswordReset(email: string) {
  return api<{ message: string }>("/api/auth/forgot-password", {
    method: "POST",
    body: { email },
  });
}

/**
 * POST /api/auth/reset-password — completes a reset with the emailed token.
 */
export function resetCustomerPassword(token: string, password: string) {
  return api<{ message: string }>("/api/auth/reset-password", {
    method: "POST",
    body: { token, password },
  });
}

/** GET /api/account/orders — the customer's own orders, paginated. */
export function fetchCustomerOrders(token: string, page = 1, perPage = 12) {
  return api<Paginated<CustomerOrder>>(
    `/api/account/orders?page=${page}&per_page=${perPage}`,
    { token },
  );
}

// --- Session store (useSyncExternalStore, mirrors cart-context.tsx) ---

type SessionSnapshot = CustomerAuthPayload | null;

function readSession(): SessionSnapshot {
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
    return parsed as CustomerAuthPayload;
  } catch {
    // Corrupt storage — treat as logged out.
    return null;
  }
}

// Module-level cache; undefined = not yet hydrated. Hydrated once on the
// first client snapshot, survives client-side navigation (like the cart).
let cachedSession: SessionSnapshot | undefined;

function getSnapshot(): SessionSnapshot {
  if (cachedSession === undefined) {
    cachedSession = readSession();
  }
  return cachedSession;
}

function getServerSnapshot(): SessionSnapshot {
  return null;
}

const sessionListeners = new Set<() => void>();

function subscribeSession(listener: () => void): () => void {
  sessionListeners.add(listener);
  return () => {
    sessionListeners.delete(listener);
  };
}

function commitSession(next: SessionSnapshot): void {
  cachedSession = next;
  try {
    if (next === null) {
      window.localStorage.removeItem(SESSION_KEY);
    } else {
      window.localStorage.setItem(SESSION_KEY, JSON.stringify(next));
    }
  } catch {
    // Storage blocked/full — the session still holds for this page load.
  }
  for (const listener of sessionListeners) {
    listener();
  }
}

/** Persists a fresh customer session — login/register/google.
 * Only the short-lived access JWT + profile are stored; the refresh token
 * lives in its HttpOnly cookie and is NEVER written to localStorage for new
 * sessions (mitigates XSS theft). The type keeps refresh_token optional
 * solely to read legacy sessions created before the cookie flow.
 */
export function saveCustomerSession(payload: CustomerAuthPayload): void {
  commitSession({
    token: payload.token,
    user: payload.user,
  } as CustomerAuthPayload);
}

/** Clears the customer session — logout. */
export function clearCustomerSession(): void {
  commitSession(null);
}

/** Current customer session from localStorage, or null when absent/corrupt. */
export function getCustomerSession(): SessionSnapshot {
  return getSnapshot();
}

/**
 * Reactive customer session: re-renders the caller whenever it is saved or
 * cleared (so the header badge and /account reflect auth state immediately).
 */
export function useCustomerSession(): SessionSnapshot {
  return useSyncExternalStore(
    subscribeSession,
    getSnapshot,
    getServerSnapshot,
  );
}