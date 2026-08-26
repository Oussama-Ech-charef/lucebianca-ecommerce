"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";

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
 * Header visually matches the storefront header: same logo treatment, spacing,
 * typography, responsive behavior, and mobile drawer. Navigation lists exactly
 * the admin sections that exist as routes with underline active state.
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
  const [mobileState, setMobileState] = useState<{ path: string; open: boolean }>({
    path: pathname,
    open: false,
  });
  const mobileOpen = mobileState.path === pathname && mobileState.open;
  const setMobileOpen = (value: boolean | ((prev: boolean) => boolean)) => {
    setMobileState((prev) => {
      const current = prev.path === pathname && prev.open;
      const next = typeof value === "function" ? (value as (p: boolean) => boolean)(current) : value;
      return { path: pathname, open: next };
    });
  };

  useEffect(() => {
    if (getAdminSession() === null) {
      clearAdminSession();
      router.replace("/admin/login");
    }
  }, [router]);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setMobileState((prev) => ({ path: prev.path, open: false }));
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  function handleLogout() {
    clearAdminSession();
    router.replace("/admin/login");
  }

  const isActive = (href: string) =>
    pathname === href || pathname.startsWith(`${href}/`);

  return (
    <div className="flex min-h-screen flex-col bg-neutral-50">
      <header className="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8 md:h-20">
          <Link
            href="/admin/orders"
            className="flex items-center gap-2 py-1 min-w-0"
            aria-label="Luce Bianca Admin dashboard"
          >
            <Image
              src="/logo.png"
              alt=""
              width={126}
              height={84}
              priority
              className="h-8 w-auto shrink-0 sm:h-10 md:h-12"
            />
            <span className="flex flex-col leading-none">
              <span className="font-serif text-sm font-medium text-neutral-900 sm:text-base">
                Luce Bianca
              </span>
              <span className="text-[10px] uppercase tracking-[0.2em] text-neutral-500 sm:text-xs sm:tracking-[0.3em]">
                Admin
              </span>
            </span>
          </Link>

          <div className="flex items-center gap-1 sm:gap-2">
            <button
              type="button"
              onClick={handleLogout}
              className="hidden rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900 md:inline-flex"
            >
              Log out
            </button>
            <button
              type="button"
              aria-label={mobileOpen ? "Close menu" : "Open menu"}
              aria-expanded={mobileOpen}
              onClick={() => setMobileOpen((open) => !open)}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900 md:hidden"
            >
              {mobileOpen ? <CloseIcon /> : <MenuIcon />}
            </button>
          </div>
        </div>

        <div className="hidden border-t border-neutral-100 bg-white md:block">
          <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <nav
              aria-label="Admin sections"
              className="flex gap-1 overflow-x-auto sm:gap-2"
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
        </div>
      </header>

      {mobileOpen ? (
        <div className="fixed inset-0 z-[60] md:hidden">
          <button
            type="button"
            aria-label="Close menu"
            onClick={() => setMobileOpen(false)}
            className="absolute inset-0 bg-black/40"
          />
          <nav
            aria-label="Mobile admin"
            className="absolute bottom-0 right-0 top-0 flex w-72 max-w-[80%] flex-col bg-white p-6 shadow-xl"
          >
            <div className="flex items-center justify-between gap-2">
              <Link
                href="/admin/orders"
                onClick={() => setMobileOpen(false)}
                className="flex items-center gap-2"
                aria-label="Luce Bianca Admin dashboard"
              >
                <Image
                  src="/logo.png"
                  alt=""
                  width={126}
                  height={84}
                  className="h-8 w-auto"
                />
                <span className="flex flex-col leading-none">
                  <span className="font-serif text-sm font-medium text-neutral-900">
                    Luce Bianca
                  </span>
                  <span className="text-[10px] uppercase tracking-[0.2em] text-neutral-500">
                    Admin
                  </span>
                </span>
              </Link>
              <button
                type="button"
                aria-label="Close menu"
                onClick={() => setMobileOpen(false)}
                className="rounded-[4px] p-1 text-neutral-700"
              >
                <CloseIcon />
              </button>
            </div>
            <div className="mt-8 flex flex-col gap-6">
              {ADMIN_SECTIONS.map((section) => {
                const active = isActive(section.href);
                return (
                  <Link
                    key={section.href}
                    href={section.href}
                    onClick={() => setMobileOpen(false)}
                    aria-current={active ? "page" : undefined}
                    className={`font-serif text-2xl ${
                      active
                        ? "text-neutral-900 underline underline-offset-8"
                        : "text-neutral-900"
                    }`}
                  >
                    {section.label}
                  </Link>
                );
              })}
            </div>
            <button
              type="button"
              onClick={handleLogout}
              className="mt-8 rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
            >
              Log out
            </button>
            <p className="mt-auto text-xs uppercase tracking-[0.3em] text-neutral-400">
              Luce Bianca
            </p>
          </nav>
        </div>
      ) : null}

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
        {children}
      </main>
    </div>
  );
}

function MenuIcon() {
  return (
    <svg
      className="h-5 w-5"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <path d="M4 7h16M4 12h16M4 17h16" strokeLinecap="round" />
    </svg>
  );
}

function CloseIcon() {
  return (
    <svg
      className="h-5 w-5"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <path d="m6 6 12 12M18 6 6 18" strokeLinecap="round" />
    </svg>
  );
}
