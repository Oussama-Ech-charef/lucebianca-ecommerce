"use client";

import { useState, type FormEvent } from "react";

import { ApiError } from "@/lib/api";
import { subscribeToNewsletter } from "@/lib/storefront";

/**
 * Newsletter signup for the footer — POST /api/newsletter/subscribe via the
 * shared API client (CSRF token attached per call).
 *
 * Client-side email validation gives instant, specific feedback; server
 * messages surface verbatim so an already-subscribed address (HTTP 200 with
 * subscribed=false) reads as information, not an error. Success/error are
 * announced through separate polite/assertive live regions.
 */

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

type Notice = {
  tone: "success" | "info" | "error";
  message: string;
};

export default function NewsletterForm() {
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) {
      return;
    }

    const trimmed = email.trim();
    if (trimmed === "") {
      setNotice({ tone: "error", message: "Please enter your email address." });
      return;
    }
    if (!EMAIL_PATTERN.test(trimmed)) {
      setNotice({
        tone: "error",
        message: "That email address doesn't look right — please check it.",
      });
      return;
    }

    setSubmitting(true);
    setNotice(null);

    try {
      const response = await subscribeToNewsletter(trimmed);
      setNotice({
        tone: response.data.subscribed ? "success" : "info",
        message: response.data.message,
      });
      setEmail("");
    } catch (err) {
      setNotice({
        tone: "error",
        message:
          err instanceof ApiError
            ? err.message
            : "Could not subscribe right now. Please try again.",
      });
    } finally {
      setSubmitting(false);
    }
  }

  const hasError = notice?.tone === "error";

  return (
    <div>
      <p className="text-xs uppercase tracking-[0.2em] text-neutral-400">
        Newsletter
      </p>
      <h2 className="mt-3 font-serif text-lg text-neutral-900">
        Join the list
      </h2>
      <p className="mt-2 text-sm text-neutral-600">
        New arrivals, styling notes and members-only offers — a few times a
        month.
      </p>

      <form onSubmit={handleSubmit} noValidate className="mt-4">
        <label htmlFor="newsletter-email" className="sr-only">
          Email address
        </label>
        <div className="flex flex-col gap-3 sm:flex-row">
          <input
            id="newsletter-email"
            name="email"
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            disabled={submitting}
            aria-invalid={hasError}
            aria-describedby={notice ? "newsletter-notice" : undefined}
            className="w-full min-h-10 rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1 disabled:bg-neutral-50"
          />
          <button
            type="submit"
            disabled={submitting}
            className="min-h-10 shrink-0 rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:bg-neutral-300"
          >
            {submitting ? "Subscribing…" : "Subscribe"}
          </button>
        </div>
      </form>

      {notice !== null &&
        (notice.tone === "error" ? (
          <p
            key="notice-error"
            id="newsletter-notice"
            role="alert"
            className="mt-3 text-sm text-red-700"
          >
            {notice.message}
          </p>
        ) : (
          <p
            key="notice-ok"
            id="newsletter-notice"
            role="status"
            className={`mt-3 text-sm ${
              notice.tone === "success" ? "text-green-800" : "text-neutral-600"
            }`}
          >
            {notice.message}
          </p>
        ))}
    </div>
  );
}
