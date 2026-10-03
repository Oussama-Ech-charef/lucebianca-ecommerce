"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ApiError } from "@/lib/api";
import { getCustomerSession } from "@/lib/customer-auth";
import {
  fetchPaymentStatus,
  formatPrice,
  initiateCardPayment,
} from "@/lib/storefront";

type Phase = "loading" | "ready" | "redirecting" | "polling" | "paid" | "failed" | "error";

export default function PaymentView({ orderId }: { orderId: string }) {
  const searchParams = useSearchParams();
  const lookupToken = searchParams.get("token") ?? undefined;
  // CMI customer return hint (okUrl/failUrl land here with ?pay=...).
  // Informational only: the AUTHORITATIVE state always comes from polling
  // GET /api/payments/status (which reflects the server-to-server callback).
  const payHint = searchParams.get("pay");
  const returnedFromCmi = payHint === "success" || payHint === "failure";
  const [phase, setPhase] = useState<Phase>("loading");
  const [message, setMessage] = useState<string | null>(null);
  const [total, setTotal] = useState<string | null>(null);
  const [attempts, setAttempts] = useState(0);
  const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopPolling = useCallback(() => {
    if (pollTimer.current) {
      clearInterval(pollTimer.current);
      pollTimer.current = null;
    }
  }, []);

  useEffect(() => stopPolling, [stopPolling]);

  const pollStatus = useCallback(async () => {
    setPhase("polling");
    setMessage("Waiting for CMI confirmation — this updates automatically.");
    let tries = 0;
    stopPolling();
    pollTimer.current = setInterval(async () => {
      tries += 1;
      setAttempts(tries);
      try {
        const res = await fetchPaymentStatus(
          orderId,
          lookupToken,
          getCustomerSession()?.token,
        );
        setTotal(res.data.total_amount);
        if (res.data.payment_status === "paid") {
          stopPolling();
          setPhase("paid");
        } else if (res.data.payment_status === "failed") {
          stopPolling();
          setPhase("failed");
          setMessage(
            "The card payment failed and reserved stock was released. You can retry below or choose another method.",
          );
        } else if (tries >= 40) {
          // ~2 min of polling; CMI callbacks can lag — keep the page usable.
          stopPolling();
          setMessage(
            "Still pending — CMI can take a few minutes. Keep this page open or check the confirmation link later.",
          );
        }
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) {
          stopPolling();
          setPhase("error");
          setMessage("Order not found. Check your confirmation link.");
        }
      }
    }, 3000);
  }, [orderId, lookupToken, stopPolling]);

  // Initial status check: already-paid orders skip straight to confirmation.
  // When returning from CMI (?pay=...), start polling automatically — the
  // server callback may still be in flight, so "pending" is expected, not an
  // error. Without the hint the page stays in "ready" for an explicit click.
  useEffect(() => {
    let cancelled = false;
    fetchPaymentStatus(orderId, lookupToken, getCustomerSession()?.token)
      .then((res) => {
        if (cancelled) return;
        setTotal(res.data.total_amount);
        if (res.data.payment_status === "paid") {
          setPhase("paid");
        } else if (res.data.payment_status === "failed") {
          setPhase("failed");
          setMessage(
            "The card payment failed and reserved stock was released. You can retry below or choose another method.",
          );
        } else if (returnedFromCmi) {
          void pollStatus();
        } else {
          setPhase("ready");
        }
      })
      .catch((e) => {
        if (cancelled) return;
        setPhase("error");
        setMessage(
          e instanceof ApiError ? e.message : "Could not load this order.",
        );
      });
    return () => {
      cancelled = true;
    };
  }, [orderId, lookupToken, returnedFromCmi, pollStatus]);

  async function handlePayNow() {
    setPhase("redirecting");
    setMessage(null);
    try {
      const initiation = await initiateCardPayment(
        Number(orderId),
        lookupToken,
        getCustomerSession()?.token,
      );
      const form = document.createElement("form");
      form.method = "POST";
      form.action = initiation.data.gateway_url;
      form.style.display = "none";
      for (const [key, value] of Object.entries(initiation.data.form_fields)) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = key;
        input.value = String(value);
        form.appendChild(input);
      }
      document.body.appendChild(form);
      // Start polling in case the customer returns via back-navigation.
      void pollStatus();
      form.submit();
    } catch (e) {
      setPhase("error");
      setMessage(
        e instanceof ApiError ? e.message : "Could not start card payment.",
      );
    }
  }

  const confirmationHref =
    lookupToken != null
      ? `/order-confirmation?id=${encodeURIComponent(orderId)}&token=${encodeURIComponent(lookupToken)}`
      : `/order-confirmation?id=${encodeURIComponent(orderId)}`;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 px-6 py-12">
      <p className="text-xs uppercase tracking-[0.3em] text-neutral-500">
        Card payment
      </p>
      <h1 className="mt-2 font-serif text-3xl">Order #{orderId}</h1>
      {total != null ? (
        <p className="mt-2 text-sm text-neutral-600">
          Amount due:{" "}
          <span className="font-medium tabular-nums">{formatPrice(total)}</span>
        </p>
      ) : null}

      {phase === "loading" ? <p className="mt-6 text-sm">Loading…</p> : null}

      {phase === "ready" ? (
        <div className="mt-6 space-y-4">
          <p className="text-sm text-neutral-600">
            You will be redirected to CMI&apos;s secure page to complete 3-D
            Secure. Your items stay reserved for 30 minutes.
          </p>
          <button
            type="button"
            onClick={handlePayNow}
            className="w-full rounded-lg bg-neutral-900 px-6 py-4 text-sm font-medium text-white hover:bg-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
          >
            Pay securely by card
          </button>
          <button
            type="button"
            onClick={() => void pollStatus()}
            className="w-full text-sm text-neutral-600 underline underline-offset-4 hover:text-neutral-900"
          >
            I already paid — check status
          </button>
        </div>
      ) : null}

      {phase === "redirecting" ? (
        <p role="status" className="mt-6 text-sm">
          Redirecting to CMI…
        </p>
      ) : null}

      {phase === "polling" ? (
        <div className="mt-6 space-y-4">
          <p role="status" className="text-sm text-neutral-600">
            {message} {attempts > 0 ? `(check ${attempts})` : null}
          </p>
          <Link
            href={confirmationHref}
            className="inline-block rounded-lg border border-neutral-300 px-6 py-3 text-sm font-medium hover:border-neutral-900"
          >
            View order
          </Link>
        </div>
      ) : null}

      {phase === "paid" ? (
        <div className="mt-6 space-y-4">
          <p role="status" className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            Payment confirmed. Thank you!
          </p>
          <Link
            href={confirmationHref}
            className="inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white hover:bg-neutral-700"
          >
            View confirmation
          </Link>
        </div>
      ) : null}

      {phase === "failed" ? (
        <div className="mt-6 space-y-4">
          <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {message ?? "Payment failed."}
          </p>
          <button
            type="button"
            onClick={handlePayNow}
            className="w-full rounded-lg bg-neutral-900 px-6 py-4 text-sm font-medium text-white hover:bg-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
          >
            Retry card payment
          </button>
          <Link
            href={confirmationHref}
            className="block text-center text-sm text-neutral-600 underline underline-offset-4 hover:text-neutral-900"
          >
            View order
          </Link>
        </div>
      ) : null}

      {phase === "error" ? (
        <p role="alert" className="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          {message ?? "Something went wrong."}
        </p>
      ) : null}

      {phase !== "loading" && phase !== "paid" && message && phase !== "failed" && phase !== "error" ? (
        <p className="mt-4 text-xs text-neutral-500">{message}</p>
      ) : null}
    </main>
  );
}
