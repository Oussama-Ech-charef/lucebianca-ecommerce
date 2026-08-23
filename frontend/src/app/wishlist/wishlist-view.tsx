"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api";
import {
  clearCustomerSession,
  useCustomerSession,
} from "@/lib/customer-auth";
import { formatPrice } from "@/lib/storefront";
import { removeFromWishlist, fetchWishlist } from "@/lib/wishlist";
import type { WishlistItem } from "@/lib/types";

/**
 * /wishlist — the signed-in customer's saved products.
 *
 * Requires a customer session: signed-out visitors get a clear sign-in panel
 * (derived from the reactive session — no silent redirects). Items load from
 * GET /api/wishlist with the customer JWT; removal uses the CSRF-guarded
 * DELETE endpoint. A 401 anywhere means the token expired — the session is
 * cleared so the visitor sees the sign-in panel again.
 */
export default function WishlistView() {
  const session = useCustomerSession();
  const [items, setItems] = useState<WishlistItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [removingId, setRemovingId] = useState<number | null>(null);
  // Bumped by "Try again" to re-run the load effect.
  const [reloadKey, setReloadKey] = useState(0);

  const token = session?.token ?? null;

  useEffect(() => {
    if (token === null) {
      return;
    }
    let cancelled = false;

    fetchWishlist(token)
      .then((response) => {
        if (cancelled) {
          return;
        }
        setItems(response.data);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          clearCustomerSession();
          return;
        }
        setError(
          err instanceof Error
            ? err.message
            : "Could not load your wishlist. Try again.",
        );
      });

    return () => {
      cancelled = true;
    };
  }, [token, reloadKey]);

  async function handleRemove(item: WishlistItem) {
    if (token === null || removingId !== null) {
      return;
    }
    setError(null);
    setRemovingId(item.product_id);
    try {
      await removeFromWishlist(item.product_id, token);
      setItems((prev) =>
        prev ? prev.filter((row) => row.product_id !== item.product_id) : prev,
      );
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        clearCustomerSession();
        return;
      }
      setError(
        err instanceof Error
          ? err.message
          : `Could not remove ${item.name}. Try again.`,
      );
    } finally {
      setRemovingId(null);
    }
  }

  if (session === null) {
    return (
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-16">
        <div className="mx-auto max-w-md rounded-lg border border-neutral-200 p-8 text-center">
          <h1 className="font-serif text-3xl">Wishlist</h1>
          <p className="mt-3 text-sm text-neutral-600">
            Sign in to save products you love and find them here on any device.
          </p>
          <Link
            href="/login"
            className="mt-6 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700"
          >
            Sign in
          </Link>
          <p className="mt-4 text-sm text-neutral-600">
            New to Luce Bianca?{" "}
            <Link
              href="/register"
              className="font-medium text-neutral-900 underline underline-offset-4"
            >
              Create an account
            </Link>
          </p>
        </div>
      </main>
    );
  }

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Wishlist</h1>

      {error !== null && (
        <div
          role="alert"
          className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
          <p>{error}</p>
          <button
            type="button"
            onClick={() => setReloadKey((key) => key + 1)}
            className="mt-2 font-medium underline underline-offset-4"
          >
            Try again
          </button>
        </div>
      )}

      {items === null && error === null ? (
        <p className="mt-6 text-sm text-neutral-500">Loading your wishlist…</p>
      ) : items !== null && items.length === 0 ? (
        <div className="mt-6 rounded-lg border border-neutral-200 p-6">
          <p className="text-sm text-neutral-600">
            Your wishlist is empty. Tap “Save to wishlist” on any product to
            keep it for later.
          </p>
          <Link
            href="/shop"
            className="mt-3 inline-block text-sm font-medium text-neutral-900 underline underline-offset-4"
          >
            Browse the shop
          </Link>
        </div>
      ) : (
        <ul className="mt-6 divide-y divide-neutral-200 border-y border-neutral-200">
          {(items ?? []).map((item) => (
            <li
              key={item.id}
              className="flex flex-col gap-3 py-5 sm:flex-row sm:items-center sm:justify-between"
            >
              <div className="min-w-0">
                <Link
                  href={`/product/${item.slug}`}
                  className="font-serif text-base text-neutral-900 underline-offset-4 hover:underline"
                >
                  {item.name}
                </Link>
                <p className="mt-1 text-xs text-neutral-500">
                  Saved{" "}
                  {new Date(item.created_at).toLocaleDateString(undefined, {
                    year: "numeric",
                    month: "long",
                    day: "numeric",
                  })}
                </p>
                {!item.is_active ? (
                  <p className="mt-1 text-xs font-medium text-amber-700">
                    Currently unavailable
                  </p>
                ) : null}
              </div>
              <div className="flex shrink-0 items-center justify-between gap-4 sm:justify-end">
                <p className="font-serif text-lg tabular-nums">
                  {formatPrice(Number(item.base_price))}
                </p>
                <button
                  type="button"
                  onClick={() => void handleRemove(item)}
                  disabled={removingId !== null}
                  aria-label={`Remove ${item.name} from wishlist`}
                  className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-red-700 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {removingId === item.product_id ? "Removing…" : "Remove"}
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </main>
  );
}
