import type { Metadata } from "next";
import { Suspense } from "react";

import VerifyEmailView from "./verify-email-view";

export const metadata: Metadata = { title: "Verify your email" };

/**
 * /verify-email — confirm a customer's email address (phase 16).
 *
 * The one-time token arrives as ?token=… in the link emailed at registration
 * (backend builds it from api/.env SITE_URL). Verification runs in the
 * browser against GET /api/auth/verify-email; the client view reads the token
 * via useSearchParams, which must sit under a Suspense boundary so the page
 * can still be prerendered.
 */
export default function VerifyEmailPage() {
  return (
    <Suspense
      fallback={
        <main className="mx-auto w-full max-w-md flex-1 px-6 py-14">
          <p className="text-sm text-neutral-500">Checking your link…</p>
        </main>
      }
    >
      <VerifyEmailView />
    </Suspense>
  );
}