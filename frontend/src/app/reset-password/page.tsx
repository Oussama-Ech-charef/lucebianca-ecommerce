import type { Metadata } from "next";
import { Suspense } from "react";

import ResetPasswordView from "./reset-password-view";

export const metadata: Metadata = {
  title: "Reset password",
  robots: { index: false, follow: false },
};

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<main className="mx-auto w-full max-w-md flex-1 px-6 py-16"><p className="text-sm">Loading…</p></main>}>
      <ResetPasswordView />
    </Suspense>
  );
}
