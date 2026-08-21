"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import GoogleSignInButton from "@/components/google-signin-button";
import {
  getCustomerSession,
  registerCustomer,
  saveCustomerSession,
} from "@/lib/customer-auth";

/**
 * /register — create a customer account (phase 13).
 *
 * Mirrors the API's server-side rules: required name/email, valid email,
 * password >= 8 characters (enforced by AuthService). On success the returned
 * session is persisted and the user lands on /account (verification is
 * informational — an account works before its email is verified). Phase 16:
 * the API emails a verification link after registration; the note under the
 * password field tells the user to expect it, and /account offers a resend.
 * Google OAuth creates the account automatically (verified email) when
 * configured.
 */

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none";

export default function RegisterView() {
  const router = useRouter();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Already signed in? Go straight to the account page.
  useEffect(() => {
    if (getCustomerSession() !== null) {
      router.replace("/account");
    }
  }, [router]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);

    if (name.trim() === "" || email.trim() === "" || password === "") {
      setError("Name, email and password are required.");
      return;
    }
    if (password.length < 8) {
      setError("Password must be at least 8 characters.");
      return;
    }

    setSubmitting(true);
    try {
      const payload = await registerCustomer(
        name.trim(),
        email.trim(),
        password,
        phone.trim(),
      );
      saveCustomerSession(payload);
      router.replace("/account");
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Registration failed. Try again.",
      );
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Create an account</h1>
      <p className="mt-2 text-sm text-neutral-500">
        Join Luce Bianca for faster checkout and order history.
      </p>

      <form onSubmit={handleSubmit} className="mt-8" noValidate>
        <label
          htmlFor="register-name"
          className="block text-sm font-medium text-neutral-700"
        >
          Full name
        </label>
        <input
          id="register-name"
          type="text"
          autoComplete="name"
          required
          value={name}
          onChange={(event) => setName(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="register-email"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Email
        </label>
        <input
          id="register-email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="register-phone"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Phone <span className="font-normal text-neutral-400">(optional)</span>
        </label>
        <input
          id="register-phone"
          type="tel"
          autoComplete="tel"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="register-password"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Password
        </label>
        <input
          id="register-password"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          className={inputClass}
        />
        <p className="mt-1.5 text-xs text-neutral-400">
          At least 8 characters.
        </p>
        <p className="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-xs text-neutral-600">
          After creating your account we&apos;ll send a verification email to
          confirm your address.
        </p>

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
          {submitting ? "Creating account…" : "Create account"}
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
        Already have an account?{" "}
        <Link
          href="/login"
          className="font-medium text-neutral-900 underline underline-offset-4"
        >
          Sign in
        </Link>
      </p>
    </main>
  );
}