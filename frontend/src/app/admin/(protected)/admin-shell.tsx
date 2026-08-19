"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";

import { clearAdminSession, getAdminSession } from "@/lib/admin";

/**
 * Protected admin shell (phase 9 — order management only).
 *
 * Guards /admin/* pages beyond the server-side proxy cookie check: on mount it
 * verifies the admin session actually exists in localStorage (a cookie could
 * survive a cleared storage, or the JWT could have expired). When it's gone it
 * clears the gate cookie and redirects to /admin/login — the same mechanism
 * the proxy uses, kept consistent. Nav shows only what this phase ships
 * (Orders); dashboard/products/customers etc. are later phases.
 */
export default function AdminShell({ children }: { children: ReactNode }) {
  const router = useRouter();

  useEffect(() => {
    if (getAdminSession() === null) {
      clearAdminSession();
      router.replace("/admin/login");
    }
  }, [router]);

  function handleLogout() {
    clearAdminSession();
    router.replace("/admin/login");
  }

  return (
    <div className="flex min-h-screen flex-col bg-neutral-50">
      <header className="border-b border-neutral-200 bg-white">
        <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 sm:px-6">
          <div className="flex items-baseline gap-3">
            <span className="font-serif text-lg">Luce Bianca</span>
            <span className="text-xs uppercase tracking-[0.3em] text-neutral-500">
              Admin
            </span>
          </div>
          <div className="flex items-center gap-4">
            <nav>
              <Link
                href="/admin/orders"
                className="text-sm font-medium text-neutral-700 underline-offset-4 hover:underline"
              >
                Orders
              </Link>
            </nav>
            <button
              type="button"
              onClick={handleLogout}
              className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
            >
              Log out
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
        {children}
      </main>
    </div>
  );
}