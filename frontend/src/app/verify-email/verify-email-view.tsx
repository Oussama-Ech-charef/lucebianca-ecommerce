"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";

import {
  resendCustomerVerification,
  verifyCustomerEmail,
} from "@/lib/customer-auth";

/**
 * VerifyEmailView — the client half of /verify-email.
 *
 * Reads ?token=… and calls GET /api/auth/verify-email once on mount. States:
 * verifying → verified (green box, links onward) or failed (the API's clean
 * message plus a resend form — an invalid/expired link can be replaced by
 * requesting a fresh one for the account's email).
 */

type Status = "verifying" | "verified" | "error";

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none";

export default function VerifyEmailView() {
  const searchParams = useSearchParams();
  const token = searchParams.get("token");

  const missingToken = token === null || token === "";
  // Initial state derives from the URL on first render — no effect needed.
  const [status, setStatus] = useState<Status>(() =>
    missingToken ? "error" : "verifying",
  );
  const [message, setMessage] = useState<string | null>(() =>
    missingToken
      ? "This link is missing its verification token. Open the full link from your email."
      : null,
  );

  // The resend form is only relevant when verification failed.
  const [resendEmail, setResendEmail] = useState("");
  const [resending, setResending] = useState(false);
  const [resendNotice, setResendNotice] = useState<string | null>(null);
  const [resendError, setResendError] = useState<string | null>(null);

  // The verification link is single-use, so it must be consumed exactly once
  // even though React StrictMode (default in Next dev) mounts effects twice.
  const verifyFired = useRef(false);

  useEffect(() => {
    if (missingToken || verifyFired.current) {
      return;
    }
    verifyFired.current = true;

    verifyCustomerEmail(token as string)
      .then(() => {
        setStatus("verified");
        setMessage(null);
      })
      .catch((err: unknown) => {
        setStatus("error");
        setMessage(
          err instanceof Error
            ? err.message
            : "We couldn't verify your email. Try again.",
        );
      });
  }, [token, missingToken]);

  async function handleResend(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setResendError(null);
    setResendNotice(null);

    if (resendEmail.trim() === "") {
      setResendError("Enter the email you registered with.");
      return;
    }

    setResending(true);
    try {
      await resendCustomerVerification(resendEmail.trim());
      setResendNotice(
        "If that email belongs to an unverified account, a fresh verification email is on its way.",
      );
    } catch (err) {
      setResendError(
        err instanceof Error
          ? err.message
          : "Could not resend the verification email. Try again.",
      );
    } finally {
      setResending(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-md flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Verify your email</h1>

      {status === "verifying" && (
        <p className="mt-6 text-sm text-neutral-500">Verifying your email…</p>
      )}

      {status === "verified" && (
        <>
          <p
            role="status"
            className="mt-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
          >
            Your email address has been verified. Thank you!
          </p>
          <div className="mt-6 flex flex-col gap-3 sm:flex-row">
            <Link
              href="/account"
              className="inline-block rounded-lg bg-neutral-900 px-5 py-3 text-center text-sm font-medium text-white transition-colors hover:bg-neutral-700"
            >
              Go to my account
            </Link>
            <Link
              href="/shop"
              className="inline-block rounded-lg border border-neutral-300 px-5 py-3 text-center text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
            >
              Continue shopping
            </Link>
          </div>
        </>
      )}

      {status === "error" && (
        <>
          <p
            role="alert"
            className="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
          >
            {message}
          </p>

          <form onSubmit={handleResend} className="mt-8" noValidate>
            <p className="text-sm text-neutral-600">
              Request a fresh link — enter the email you registered with.
            </p>
            <label
              htmlFor="verify-email"
              className="mt-5 block text-sm font-medium text-neutral-700"
            >
              Email
            </label>
            <input
              id="verify-email"
              type="email"
              autoComplete="email"
              value={resendEmail}
              onChange={(event) => setResendEmail(event.target.value)}
              className={inputClass}
            />

            {resendError !== null && (
              <p
                role="alert"
                className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
              >
                {resendError}
              </p>
            )}
            {resendNotice !== null && (
              <p
                role="status"
                className="mt-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
              >
                {resendNotice}
              </p>
            )}

            <button
              type="submit"
              disabled={resending}
              className="mt-6 w-full rounded-lg bg-neutral-900 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {resending ? "Sending…" : "Resend verification email"}
            </button>
          </form>
        </>
      )}
    </main>
  );
}