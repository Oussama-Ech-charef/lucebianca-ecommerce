/**
 * API client — single place where the frontend talks to the PHP API.
 *
 * Base URL is centralized in `lib/config.ts` (`API_URL`); it falls back to
 * `http://127.0.0.1:8090` so `next dev` still works if the env is missing.
 * In production a missing `NEXT_PUBLIC_API_URL` is surfaced by
 * `warnIfPlaceholderConfig()` instead of inventing a URL.
 */
import { API_URL as CONFIGURED_API_URL } from "./config";

const API_BASE_URL = CONFIGURED_API_URL;

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "DELETE";
  body?: unknown;
  token?: string;
  headers?: Record<string, string>;
  /** Server-only fetch hint (Next RequestInit `next`), e.g. ISR revalidate. */
  next?: NextFetchRequestConfig;
  /** Next.js fetch cache mode (e.g. "no-store", "force-cache"). */
  cache?: RequestCache;
};

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export async function api<T>(
  path: string,
  { method = "GET", body, token, headers, next, cache }: RequestOptions = {},
): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    ...(next ? { next } : {}),
    ...(cache ? { cache } : {}),
  });

  const data = (await response.json().catch(() => null)) as T | null;

  if (!response.ok) {
    const message =
      data && typeof data === "object" && "error" in data
        ? String((data as { error: unknown }).error)
        : `Request failed (${response.status})`;
    throw new ApiError(response.status, message);
  }

  return data as T;
}

/**
 * GET /api/csrf-token — a fresh CSRF token for state-changing requests.
 * CsrfMiddleware (api/src/Core/CsrfMiddleware.php) validates it from the
 * `X-CSRF-Token` header on every POST/PUT/DELETE route it guards.
 */
export async function fetchCsrfToken(): Promise<string> {
  const data = await api<{ csrf_token: string }>("/api/csrf-token");
  return data.csrf_token;
}