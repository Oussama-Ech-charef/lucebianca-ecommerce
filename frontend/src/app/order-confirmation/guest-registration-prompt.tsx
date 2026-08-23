"use client";

import Link from "next/link";
import { useState } from "react";

import { useCustomerSession } from "@/lib/customer-auth";

/**
 * Post-checkout account prompt for guest customers, shown on the order
 * confirmation page.
 *
 * Renders nothing once a customer session exists (the reactive store hides
 * it immediately after login/register, mirroring the header's account icon).
 * Dismissing only removes the panel — the order confirmation above it is
 * untouched. The registration path is a plain /register link: the app has no
 * prefill support and order/email data must never travel in URLs.
 */
export default function GuestRegistrationPrompt() {
  const session = useCustomerSession();
  const [dismissed, setDismissed] = useState(false);

  if (session !== null || dismissed) {
    return null;
  }

  return (
    <section
      aria-label="Create an account"
      className="mt-10 rounded-lg border border-neutral-200 bg-neutral-50 p-6"
    >
      <div className="flex items-start justify-between gap-4">
        <h2 className="font-serif text-lg">Keep this order close</h2>
        <button
          type="button"
          onClick={() => setDismissed(true)}
          aria-label="Dismiss account prompt"
          className="shrink-0 rounded p-1 text-neutral-400 transition-colors hover:text-neutral-900"
        >
          <svg
            className="h-5 w-5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            aria-hidden="true"
          >
            <path d="m6 6 12 12M18 6 6 18" strokeLinecap="round" />
          </svg>
        </button>
      </div>

      <p className="mt-2 max-w-prose text-sm text-neutral-600">
        You checked out as a guest. Create a free account to track orders and
        keep your details saved for faster checkout next time.
      </p>

      <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <Link
          href="/register"
          className="inline-block rounded-lg bg-neutral-900 px-5 py-2.5 text-center text-sm font-medium text-white transition-colors hover:bg-neutral-700"
        >
          Create an account
        </Link>
        <button
          type="button"
          onClick={() => setDismissed(true)}
          className="rounded-lg px-3 py-2.5 text-sm text-neutral-500 underline underline-offset-4 transition-colors hover:text-neutral-900"
        >
          Maybe later
        </button>
      </div>
    </section>
  );
}
