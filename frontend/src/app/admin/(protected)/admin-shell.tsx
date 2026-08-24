"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";

import { clearAdminSession, getAdminSession } from "@/lib/admin";

/**
 * Protected admin shell.
 *
 * Guards /admin/* pages beyond the server-side proxy cookie check: on mount it
 * verifies the admin session actually exists in localStorage (a cookie could
 * survive a cleared storage, or the JWT could have expired). When it's gone it
 * clears the gate cookie and redirects to /admin/login — the same mechanism
 * the proxy uses, kept consistent.
 *
 * Navigation lists exactly the admin sections that exist as routes (no
 * placeholders) with an underline active state; on small screens the row
 * scrolls horizontally instead of wrapping or hiding entries.
 */

const ADMIN_SECTIONS = [
  { label: "Orders", href: "/admin/orders" },
  { label: "Products", href: "/admin/products" },
  { label: "Categories", href: "/admin/categories" },
  { label: "Customers", href: "/admin/customers" },
  { label: "Reviews", href: "/admin/reviews" },
  { label: "Messages", href: "/admin/messages" },
  { label: "Settings", href: "/admin/settings" },
];

export default function AdminShell({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();

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

  const isActive = (href: string) =>
    pathname === href || pathname.startsWith(`${href}/`);

  return (
    <div className="flex min-h-screen flex-col bg-neutral-50">
      <header className="border-b border-neutral-200 bg-white">
        <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
          <div className="flex h-16 items-center justify-between gap-4">
            <div className="flex items-baseline gap-3">
              <span className="font-serif text-lg">Luce Bianca</span>
              <span className="text-xs uppercase tracking-[0.3em] text-neutral-500">
                Admin
              </span>
            </div>
            <button
              type="button"
              onClick={handleLogout}
              className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
            >
              Log out
            </button>
          </div>

          <nav
            aria-label="Admin sections"
            className="-mb-px flex gap-1 overflow-x-auto sm:gap-2"
          >
            {ADMIN_SECTIONS.map((section) => {
              const active = isActive(section.href);
              return (
                <Link
                  key={section.href}
                  href={section.href}
                  aria-current={active ? "page" : undefined}
                  className={`whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors ${
                    active
                      ? "border-neutral-900 font-medium text-neutral-900"
                      : "border-transparent font-medium text-neutral-500 hover:border-neutral-300 hover:text-neutral-900"
                  }`}
                >
                  {section.label}
                </Link>
              );
            })}
          </nav>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
        {children}
      </main>
    </div>
  );
}