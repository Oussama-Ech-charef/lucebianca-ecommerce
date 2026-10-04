"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { ApiError } from "@/lib/api";
import { requestPasswordReset } from "@/lib/customer-auth";

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";

export default function ForgotPasswordView() {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setMessage(null);
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError("Please enter a valid email address.");
      return;
    }
    setSubmitting(true);
    try {
      const res = await requestPasswordReset(email.trim());
      setMessage(res.message);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-16">
      <h1 className="font-serif text-3xl">Forgot password</h1>
      <p className="mt-2 text-sm text-neutral-500">
        Enter your account email — if it exists, a 1-hour single-use reset link
        is on its way.
      </p>
      <form onSubmit={handleSubmit} noValidate className="mt-8 space-y-4">
        <div>
          <label htmlFor="forgot-email" className="text-sm text-neutral-700">
            Email
          </label>
          <input
            id="forgot-email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="you@example.com"
            className={inputClass}
          />
        </div>
        {error ? (
          <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {error}
          </p>
        ) : null}
        {message ? (
          <p role="status" className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
            {message}
          </p>
        ) : null}
        <button
          type="submit"
          disabled={submitting}
          aria-busy={submitting}
          className="w-full rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
        >
          {submitting ? "Sending…" : "Send reset link"}
        </button>
      </form>
      <p className="mt-6 text-center text-sm text-neutral-500">
        <Link href="/login" className="underline underline-offset-4 hover:text-neutral-900">
          Back to sign in
        </Link>
      </p>
    </main>
  );
}
