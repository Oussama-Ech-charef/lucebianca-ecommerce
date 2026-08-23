"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api";
import {
  clearCustomerSession,
  useCustomerSession,
} from "@/lib/customer-auth";
import { addToWishlist, fetchWishlist, removeFromWishlist } from "@/lib/wishlist";

/**
 * Wishlist toggle for the product page.
 *
 * Signed out: a clear "Sign in to save" link — the wishlist requires a
 * customer session. Signed in: a heart toggle that reflects the server
 * state (GET /api/wishlist on mount) and adds/removes via the CSRF-guarded
 * POST/DELETE endpoints.
 */
export default function WishlistButton({
  productId,
  productName,
}: {
  productId: number;
  productName: string;
}) {
  const session = useCustomerSession();
  const [saved, setSaved] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const token = session?.token ?? null;

  // Reflect the server's wishlist state once we have a session. An expired
  // token (401) clears the session so the control falls back to sign-in.
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
        setSaved(response.data.some((item) => item.product_id === productId));
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          clearCustomerSession();
        }
        // Other failures leave the toggle neutral; the next click retries.
      });

    return () => {
      cancelled = true;
    };
  }, [token, productId]);

  async function handleToggle() {
    if (token === null || pending) {
      return;
    }
    setError(null);
    setPending(true);

    const wasSaved = saved;
    try {
      if (wasSaved) {
        await removeFromWishlist(productId, token);
        setSaved(false);
      } else {
        await addToWishlist(productId, token);
        setSaved(true);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        clearCustomerSession();
      } else {
        setError(
          err instanceof Error ? err.message : "Could not update your wishlist.",
        );
        setSaved(wasSaved);
      }
    } finally {
      setPending(false);
    }
  }

  if (token === null) {
    return (
      <p className="mt-4 text-sm">
        <Link
          href="/login"
          className="inline-flex items-center gap-2 font-medium text-neutral-900 underline underline-offset-4"
        >
          <HeartIcon filled={false} />
          Sign in to save this to your wishlist
        </Link>
      </p>
    );
  }

  return (
    <div className="mt-4">
      <button
        type="button"
        onClick={handleToggle}
        disabled={pending}
        aria-pressed={saved}
        aria-label={
          saved
            ? `Remove ${productName} from wishlist`
            : `Save ${productName} to wishlist`
        }
        className={`inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
          saved
            ? "border-neutral-900 bg-neutral-900 text-white hover:bg-neutral-700"
            : "border-neutral-300 text-neutral-700 hover:border-neutral-900 hover:text-neutral-900"
        }`}
      >
        <HeartIcon filled={saved} />
        {pending
          ? saved
            ? "Removing…"
            : "Saving…"
          : saved
            ? "Saved to wishlist"
            : "Save to wishlist"}
      </button>
      {error !== null && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {error}
        </p>
      )}
      {saved && error === null && !pending && (
        <p className="mt-2 text-xs text-neutral-500">
          Saved —{" "}
          <Link href="/wishlist" className="underline underline-offset-2">
            view your wishlist
          </Link>
        </p>
      )}
    </div>
  );
}

function HeartIcon({ filled }: { filled: boolean }) {
  return (
    <svg
      className="h-5 w-5"
      viewBox="0 0 24 24"
      fill={filled ? "currentColor" : "none"}
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <path d="M12 20.5S3.5 15.5 3.5 9.7A4.7 4.7 0 0 1 12 7a4.7 4.7 0 0 1 8.5 2.7c0 5.8-8.5 10.8-8.5 10.8Z" />
    </svg>
  );
}
