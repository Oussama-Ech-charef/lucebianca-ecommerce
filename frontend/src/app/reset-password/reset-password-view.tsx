"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState, type FormEvent } from "react";

import { ApiError } from "@/lib/api";
import { resetCustomerPassword } from "@/lib/customer-auth";

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";

export default function ResetPasswordView() {
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setMessage(null);
    if (!token) {
      setError("This reset link is missing its token. Request a new one.");
      return;
    }
    if (password.length < 8) {
      setError("Password must be at least 8 characters.");
      return;
    }
    if (password !== confirm) {
      setError("Passwords do not match.");
      return;
    }
    setSubmitting(true);
    try {
      const res = await resetCustomerPassword(token, password);
      setMessage(res.message);
      setPassword("");
      setConfirm("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-16">
      <h1 className="font-serif text-3xl">Reset password</h1>
      <p className="mt-2 text-sm text-neutral-500">
        Choose a new password (single-use link, expires in 1 hour).
      </p>
      <form onSubmit={handleSubmit} noValidate className="mt-8 space-y-4">
        <div>
          <label htmlFor="reset-password" className="text-sm text-neutral-700">
            New password
          </label>
          <input
            id="reset-password"
            type="password"
            autoComplete="new-password"
            required
            minLength={8}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="At least 8 characters"
            className={inputClass}
          />
        </div>
        <div>
          <label htmlFor="reset-confirm" className="text-sm text-neutral-700">
            Confirm password
          </label>
          <input
            id="reset-confirm"
            type="password"
            autoComplete="new-password"
            required
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
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
            {message}{" "}
            <Link href="/login" className="underline underline-offset-4">Sign in</Link>
          </p>
        ) : null}
        <button
          type="submit"
          disabled={submitting}
          aria-busy={submitting}
          className="w-full rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
        >
          {submitting ? "Resetting…" : "Reset password"}
        </button>
      </form>
    </main>
  );
}
