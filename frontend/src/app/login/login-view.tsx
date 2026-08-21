"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import GoogleSignInButton from "@/components/google-signin-button";
import {
  getCustomerSession,
  loginCustomer,
  saveCustomerSession,
} from "@/lib/customer-auth";

/**
 * /login — customer sign in (phase 13).
 *
 * Storefront-branded, distinct from the admin login. A successful login
 * persists the customer session under `lucebianca:customer:session:v1`
 * (never the admin key) and goes to /account. API errors (401 invalid
 * credentials, 422 validation) surface verbatim. Google OAuth is offered
 * when NEXT_PUBLIC_GOOGLE_CLIENT_ID is configured.
 */

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none";

export default function LoginView() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Already signed in? Go straight to the account page (reads localStorage
  // directly so a saved session is honored on first client paint).
  useEffect(() => {
    if (getCustomerSession() !== null) {
      router.replace("/account");
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
      const payload = await loginCustomer(email.trim(), password);
      saveCustomerSession(payload);
      router.replace("/account");
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Sign in failed. Try again.",
      );
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Sign in</h1>
      <p className="mt-2 text-sm text-neutral-500">
        Welcome back to Luce Bianca.
      </p>

      <form onSubmit={handleSubmit} className="mt-8" noValidate>
        <label
          htmlFor="login-email"
          className="block text-sm font-medium text-neutral-700"
        >
          Email
        </label>
        <input
          id="login-email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="login-password"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Password
        </label>
        <input
          id="login-password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          className={inputClass}
        />

        {error !== null && (
          <p
            role="alert"
            className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
          >
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={submitting}
          className="mt-6 w-full rounded-lg bg-neutral-900 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>

      <div className="my-6 flex items-center gap-4">
        <span className="h-px flex-1 bg-neutral-200" />
        <span className="text-xs uppercase tracking-[0.2em] text-neutral-400">
          or
        </span>
        <span className="h-px flex-1 bg-neutral-200" />
      </div>

      <GoogleSignInButton />

      <p className="mt-8 text-center text-sm text-neutral-600">
        New to Luce Bianca?{" "}
        <Link
          href="/register"
          className="font-medium text-neutral-900 underline underline-offset-4"
        >
          Create an account
        </Link>
      </p>
    </main>
  );
}