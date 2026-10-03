import type { Metadata } from "next";
import { Suspense } from "react";

import UnsubscribeView from "./unsubscribe-view";

export const metadata: Metadata = {
  title: "Newsletter unsubscribe",
  robots: { index: false, follow: false },
};

export default function NewsletterUnsubscribePage() {
  return (
    <Suspense fallback={<main className="mx-auto w-full max-w-md flex-1 px-6 py-16"><p className="text-sm">Loading…</p></main>}>
      <UnsubscribeView />
    </Suspense>
  );
}
