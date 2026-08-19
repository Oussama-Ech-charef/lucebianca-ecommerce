"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import {
  getAdminSession,
  loginAdmin,
  saveAdminSession,
} from "@/lib/admin";

/**
 * /admin/login — the one public admin page (spec section 7).
 *
 * Minimal, functional, deliberately separate from the storefront brand shell
 * (the spec describes no admin visual identity). On success the admin JWT is
 * stored under its own admin-only localStorage key + gate cookie and the
 * session is redirected to /admin/orders. API errors (401 invalid
 * credentials, 422 validation) surface verbatim.
 */
export default function AdminLoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Already logged in? Don't show the login form — go straight to orders.
  useEffect(() => {
    if (getAdminSession() !== null) {
      router.replace("/admin/orders");
    }
  }, [router]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);

    if (email.trim() === "" || password === "") {
      setError("Email and password are required.");
      return;
    }

    setSubmitting(true);
    try {
      const payload = await loginAdmin(email.trim(), password);
      saveAdminSession(payload);
      router.replace("/admin/orders");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login failed. Try again.");
      setSubmitting(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-neutral-50 px-4 py-12">
      <div className="w-full max-w-sm">
        <p className="text-center font-serif text-2xl">Luce Bianca</p>
        <p className="mt-1 text-center text-xs uppercase tracking-[0.3em] text-neutral-500">
          Admin panel
        </p>

        <form
          onSubmit={handleSubmit}
          className="mt-8 rounded-lg border border-neutral-200 bg-white p-6"
        >
          <label
            htmlFor="admin-email"
            className="block text-sm font-medium text-neutral-700"
          >
            Email
          </label>
          <input
            id="admin-email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm outline-none transition-colors focus:border-neutral-900 focus:ring-1 focus:ring-neutral-900"
          />

          <label
            htmlFor="admin-password"
            className="mt-4 block text-sm font-medium text-neutral-700"
          >
            Password
          </label>
          <input
            id="admin-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm outline-none transition-colors focus:border-neutral-900 focus:ring-1 focus:ring-neutral-900"
          />

          {error !== null && (
            <p
              role="alert"
              className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
            >
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="mt-6 w-full rounded-md bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? "Signing in…" : "Sign in"}
          </button>
        </form>
      </div>
    </main>
  );
}