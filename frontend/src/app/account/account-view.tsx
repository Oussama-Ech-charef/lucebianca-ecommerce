"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api";
import {
  clearCustomerSession,
  fetchCustomerAccount,
  fetchCustomerOrders,
  getCustomerSession,
  logoutCustomer,
  resendCustomerVerification,
  useCustomerSession,
} from "@/lib/customer-auth";
import { formatPrice } from "@/lib/storefront";
import type { CustomerOrder, CustomerUser, Paginated } from "@/lib/types";

/**
 * /account — the logged-in customer's profile + order history (phase 13).
 *
 * Protected client-side (there is deliberately no proxy gate for the
 * storefront): with no customer session the page redirects to /login. Data is
 * fetched fresh from the customer-scoped endpoints — GET /api/account and
 * GET /api/account/orders — using the short-lived JWT. A 401 there (expired
 * token) clears the session and returns to login. Logout revokes the refresh
 * token (best-effort) then clears the local session.
 *
 * Phase 16: when email_verified is false the page shows a notice banner with
 * a "Resend verification email" button. The banner is dismissible for the
 * session (sessionStorage flag) — verification itself is informational and
 * never blocks account access.
 */

/** sessionStorage flag — the verification banner is hidden for the session. */
const VERIFY_BANNER_DISMISS_KEY = "lucebianca:verify-banner-dismissed:v1";

export default function AccountView() {
  const router = useRouter();
  const session = useCustomerSession();
  const [profile, setProfile] = useState<CustomerUser | null>(null);
  const [orders, setOrders] = useState<Paginated<CustomerOrder> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [loggingOut, setLoggingOut] = useState(false);
  const [bannerDismissed, setBannerDismissed] = useState<boolean>(() => {
    // Restore the per-session dismissal so a refresh doesn't reset it.
    // sessionStorage is client-only; the server render always shows the banner.
    if (typeof window === "undefined") {
      return false;
    }
    try {
      return window.sessionStorage.getItem(VERIFY_BANNER_DISMISS_KEY) === "1";
    } catch {
      // Storage blocked — the banner simply stays visible.
      return false;
    }
  });
  const [resending, setResending] = useState(false);
  const [resendNotice, setResendNotice] = useState<string | null>(null);
  const [resendError, setResendError] = useState<string | null>(null);

  // Guard: reading localStorage directly honors a saved session on first
  // paint (the reactive snapshot hydrates one render later).
  useEffect(() => {
    if (getCustomerSession() === null) {
      router.replace("/login");
    }
  }, [router]);

  const token = session?.token ?? null;

  useEffect(() => {
    if (token === null) {
      return;
    }
    let cancelled = false;

    Promise.all([fetchCustomerAccount(token), fetchCustomerOrders(token)])
      .then(([account, orderList]) => {
        if (cancelled) {
          return;
        }
        setProfile(account.data);
        setOrders(orderList);
        setError(null);
        setLoading(false);
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          clearCustomerSession();
          router.replace("/login");
          return;
        }
        setError(
          err instanceof Error
            ? err.message
            : "Could not load your account. Try again.",
        );
        setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [token, router]);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      if (session?.refresh_token) {
        await logoutCustomer(session.refresh_token);
      }
    } catch {
      // Revocation is best-effort; the local session is still cleared.
    }
    clearCustomerSession();
    router.replace("/login");
  }

  async function handleResendVerification() {
    if (profile === null) {
      return;
    }
    setResending(true);
    setResendNotice(null);
    setResendError(null);
    try {
      await resendCustomerVerification(profile.email);
      setResendNotice("A fresh verification email is on its way.");
    } catch (err) {
      setResendError(
        err instanceof Error
          ? err.message
          : "Could not resend the verification email. Try again.",
      );
    } finally {
      setResending(false);
    }
  }

  function dismissVerificationBanner() {
    setBannerDismissed(true);
    try {
      window.sessionStorage.setItem(VERIFY_BANNER_DISMISS_KEY, "1");
    } catch {
      // Storage blocked — the dismissal just lasts this page load.
    }
  }

  if (loading) {
    return (
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-16">
        <p className="text-sm text-neutral-500">Loading your account…</p>
      </main>
    );
  }

  if (error !== null || profile === null) {
    return (
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-16">
        <h1 className="font-serif text-3xl">My account</h1>
        <p role="alert" className="mt-4 text-sm text-red-700">
          {error ?? "Your account could not be loaded."}
        </p>
        <Link
          href="/shop"
          className="mt-6 inline-block text-sm font-medium text-neutral-900 underline underline-offset-4"
        >
          Continue shopping
        </Link>
      </main>
    );
  }

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-14">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-serif text-3xl">My account</h1>
          <p className="mt-2 text-sm text-neutral-500">
            Welcome back, {profile.name}.
          </p>
        </div>
        <button
          type="button"
          onClick={handleLogout}
          disabled={loggingOut}
          className="rounded-lg border border-neutral-300 px-4 py-2.5 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {loggingOut ? "Signing out…" : "Sign out"}
        </button>
      </div>

      {!profile.email_verified && !bannerDismissed && (
        <div
          role="status"
          className="mt-6 flex flex-col gap-4 rounded-lg border border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-center sm:justify-between"
        >
          <div>
            <p className="text-sm font-semibold text-amber-900">
              Verify your email address
            </p>
            <p className="mt-0.5 text-sm text-amber-800">
              Confirm {profile.email} to fully activate your account.
            </p>
            {resendError !== null && (
              <p role="alert" className="mt-2 text-sm text-red-700">
                {resendError}
              </p>
            )}
            {resendNotice !== null && (
              <p className="mt-2 text-sm text-green-800">{resendNotice}</p>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-4">
            <button
              type="button"
              onClick={handleResendVerification}
              disabled={resending}
              className="rounded-lg border border-amber-300 bg-white px-4 py-2.5 text-sm font-medium text-amber-900 transition-colors hover:border-amber-900 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {resending ? "Sending…" : "Resend verification email"}
            </button>
            <button
              type="button"
              onClick={dismissVerificationBanner}
              aria-label="Dismiss verification notice"
              className="text-sm text-neutral-500 underline underline-offset-4 hover:text-neutral-900"
            >
              Dismiss
            </button>
          </div>
        </div>
      )}

      <section
        aria-label="Profile details"
        className="mt-8 rounded-lg border border-neutral-200 bg-white p-6"
      >
        <h2 className="font-serif text-xl">Profile</h2>
        <dl className="mt-4 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wider text-neutral-400">
              Name
            </dt>
            <dd className="mt-1 text-sm text-neutral-900">{profile.name}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wider text-neutral-400">
              Email
            </dt>
            <dd className="mt-1 text-sm text-neutral-900">{profile.email}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wider text-neutral-400">
              Phone
            </dt>
            <dd className="mt-1 text-sm text-neutral-900">
              {profile.phone ?? "—"}
            </dd>
          </div>
        </dl>
      </section>

      <section aria-label="Order history" className="mt-10">
        <h2 className="font-serif text-xl">Order history</h2>
        {orders !== null && orders.data.length === 0 ? (
          <div className="mt-4 rounded-lg border border-neutral-200 p-6">
            <p className="text-sm text-neutral-600">
              You have no orders yet.
            </p>
            <Link
              href="/shop"
              className="mt-3 inline-block text-sm font-medium text-neutral-900 underline underline-offset-4"
            >
              Start shopping
            </Link>
          </div>
        ) : (
          <ul className="mt-4 divide-y divide-neutral-200 border-y border-neutral-200">
            {(orders?.data ?? []).map((order) => (
              <li key={order.id} className="py-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-neutral-900">
                      Order #{order.id}
                    </p>
                    <p className="mt-0.5 text-xs text-neutral-500">
                      {new Date(order.created_at).toLocaleDateString(undefined, {
                        year: "numeric",
                        month: "long",
                        day: "numeric",
                      })}{" "}
                      · {order.item_count}{" "}
                      {order.item_count === 1 ? "item" : "items"}
                    </p>
                  </div>
                  <p className="font-serif text-lg tabular-nums">
                    {formatPrice(Number(order.total_amount))}
                  </p>
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-neutral-500">
                  <span className="capitalize">{order.status}</span>
                  <span>
                    {order.payment_method === "cod"
                      ? "Cash on delivery"
                      : order.payment_method}
                  </span>
                </div>
                <Link
                  href={`/order-confirmation?id=${order.id}`}
                  className="mt-3 inline-block text-sm font-medium text-neutral-900 underline underline-offset-4"
                >
                  View details
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
}