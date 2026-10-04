"use client";

import { useEffect } from "react";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

/**
 * Client-side error boundary for route segments.
 * Catches render errors in the component tree and displays a safe fallback.
 * In production, never shows stack traces or internal details.
 */
export default function ErrorBoundary({ error, reset }: ErrorBoundaryProps) {
  // In production, we don't log the error to the console to avoid exposing
  // internal details. The server-side error has already been logged with
  // a request ID that the user can reference.
  useEffect(() => {
    if (process.env.NODE_ENV === "development") {
      console.error("[ErrorBoundary]", error);
    }
  }, [error]);

  return (
    <div
      className="flex min-h-[60vh] items-center justify-center px-4"
      role="alert"
      aria-live="polite"
    >
      <div className="text-center max-w-md">
        <h1 className="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
          Something went wrong
        </h1>
        <p className="mt-3 text-neutral-600 dark:text-neutral-400">
          We&apos;re sorry, but an unexpected error occurred. Please try
          again — if the problem persists, contact us using the link below.
        </p>

        {/* Show request ID in production for support reference */}
        {(error.digest || process.env.NODE_ENV === "development") && (
          <details className="mt-6 text-left">
            <summary className="cursor-pointer text-sm text-neutral-500 dark:text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300">
              Technical details (for support)
            </summary>
            <pre className="mt-3 p-3 text-xs overflow-auto bg-neutral-100 dark:bg-neutral-800 rounded text-neutral-700 dark:text-neutral-300">
              {error.digest
                ? `Reference: ${error.digest}`
                : "Reference unavailable — please mention the time of the error when contacting support."}
              {process.env.NODE_ENV === "development" && (error.message || error.stack) ? `\n\n${error.message}\n${error.stack ?? ""}` : ""}
            </pre>
          </details>
        )}

        <button
          onClick={reset}
          className="mt-6 inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2 dark:focus-visible:ring-white dark:focus-visible:ring-offset-neutral-900"
          aria-label="Try again"
        >
          <svg
            className="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
          >
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
            <path d="M3 3v5h5" />
          </svg>
          Try again
        </button>

        <p className="mt-4 text-sm text-neutral-500 dark:text-neutral-500">
          If the problem persists, please{" "}
          <a
            href="/contact"
            className="underline hover:text-neutral-700 dark:hover:text-neutral-300"
          >
            contact us
          </a>
          {error.digest && <span className="ml-1"> and mention reference <code className="font-mono">{error.digest}</code></span>}
          .
        </p>
      </div>
    </div>
  );
}