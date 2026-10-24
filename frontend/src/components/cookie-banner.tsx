"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

const CONSENT_KEY = "lucebianca:cookie-consent:v1";

/**
 * Discreet cookie notice.
 *
 * Luce Bianca sets no advertising or analytics cookies today: only essential
 * first-party storage (cart, customer/admin session, localStorage preferences).
 * The banner therefore discloses that fact and stores the visitor's
 * acknowledgement locally so it never reappears on the same browser.
 */
export default function CookieBanner() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let shouldShow = false;
    try {
      shouldShow = window.localStorage.getItem(CONSENT_KEY) === null;
    } catch {
      // Storage blocked (private mode / hardened browser): show the notice for
      // this page view but do not fail the storefront.
      shouldShow = true;
    }
    if (!shouldShow) {
      return;
    }

    // Deferred one tick so the effect does not trigger a cascading render
    // synchronously during mount (react-hooks/set-state-in-effect).
    const timer = window.setTimeout(() => setVisible(true), 0);
    return () => window.clearTimeout(timer);
  }, []);

  function accept() {
    try {
      window.localStorage.setItem(CONSENT_KEY, new Date().toISOString());
    } catch {
      // The acknowledgement still dismisses the banner for this page view.
    }
    setVisible(false);
  }

  if (!visible) {
    return null;
  }

  return (
    <section
      role="region"
      aria-label="Cookie notice"
      className="fixed inset-x-4 bottom-24 z-50 mx-auto max-w-md rounded-lg border border-neutral-200 bg-white p-4 shadow-xl sm:bottom-6 sm:left-6 sm:right-auto sm:mx-0"
    >
      <p className="text-sm text-neutral-700">
        We use essential cookies and local storage to keep your cart, session
        and preferences working. We do not use advertising or analytics
        cookies.
      </p>
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={accept}
          className="rounded-[2px] bg-neutral-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
        >
          Accept &amp; continue
        </button>
        <Link
          href="/privacy"
          className="text-xs text-neutral-600 underline underline-offset-4 hover:text-neutral-900"
        >
          Privacy policy
        </Link>
      </div>
    </section>
  );
}
