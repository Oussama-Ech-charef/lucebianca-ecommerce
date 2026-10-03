"use client";

import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiError, api } from "@/lib/api";

/**
 * Signed unsubscribe confirmation.
 *
 * The emailed link is validated read-only (GET) on mount — GET never
 * mutates, so scanners/link previews cannot unsubscribe anyone. Only the
 * explicit confirmation click POSTs (state-changing) with email + token.
 */
export default function UnsubscribeView() {
  const searchParams = useSearchParams();
  const email = searchParams.get("email") ?? "";
  const token = searchParams.get("token") ?? "";
  const [linkState, setLinkState] = useState<"checking" | "valid" | "invalid">(
    // Derived initial state (not an effect): a link without both params is
    // invalid before any verification runs.
    !email || !token ? "invalid" : "checking",
  );
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [working, setWorking] = useState(false);

  useEffect(() => {
    if (linkState !== "checking") {
      return;
    }
    let cancelled = false;
    api<{ data: { valid: boolean } }>(
      `/api/newsletter/unsubscribe?email=${encodeURIComponent(email)}&token=${encodeURIComponent(token)}`,
    )
      .then(() => {
        if (!cancelled) setLinkState("valid");
      })
      .catch(() => {
        if (!cancelled) setLinkState("invalid");
      });
    return () => {
      cancelled = true;
    };
  }, [email, token, linkState]);

  async function handleUnsubscribe() {
    setError(null);
    setStatus(null);
    setWorking(true);
    try {
      const res = await api<{ data: { message: string } }>(
        "/api/newsletter/unsubscribe",
        { method: "POST", body: { email, token } },
      );
      setStatus(res.data.message);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Something went wrong.");
    } finally {
      setWorking(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-16">
      <h1 className="font-serif text-3xl">Unsubscribe</h1>
      {linkState === "checking" ? (
        <p className="mt-4 text-sm text-neutral-500">
          Checking your unsubscribe link…
        </p>
      ) : linkState === "invalid" ? (
        <p role="alert" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          This unsubscribe link is invalid or incomplete. Please use the full
          link from your newsletter email.
        </p>
      ) : (
        <>
          <p className="mt-2 text-sm text-neutral-500">
            Unsubscribe <span className="font-medium">{email}</span> from Luce
            Bianca offers?
          </p>
          {error ? (
            <p role="alert" className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
              {error}
            </p>
          ) : null}
          {status ? (
            <p role="status" className="mt-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
              {status}
            </p>
          ) : null}
          <button
            type="button"
            onClick={handleUnsubscribe}
            disabled={working}
            aria-busy={working}
            className="mt-6 w-full rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
          >
            {working ? "Unsubscribing…" : "Confirm unsubscribe"}
          </button>
        </>
      )}
    </main>
  );
}
