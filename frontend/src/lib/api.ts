/**
 * API client — single place where the frontend talks to the PHP API.
 *
 * Base URL comes from NEXT_PUBLIC_API_URL (see .env.example). Every call
 * returns typed JSON; non-2xx responses throw ApiError with the API's
 * error message so the UI can surface it.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8090";

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "DELETE";
  body?: unknown;
  token?: string;
  headers?: Record<string, string>;
  /** Server-only fetch hint (Next RequestInit `next`), e.g. ISR revalidate. */
  next?: NextFetchRequestConfig;
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
  { method = "GET", body, token, headers, next }: RequestOptions = {},
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