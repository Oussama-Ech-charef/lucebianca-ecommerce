"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";

import { useCart } from "@/lib/cart-context";
import { useCustomerSession } from "@/lib/customer-auth";
import { formatPrice } from "@/lib/storefront";

// Storefront routes. /wishlist requires a customer session — signed-out
// visitors get a sign-in prompt on that page rather than a hidden link.
const NAV_LINKS = [
  { label: "Shop", href: "/shop" },
  { label: "Wishlist", href: "/wishlist" },
];

type OverlayKey = "mobile" | "search" | "cart";

export default function Header() {
  const pathname = usePathname();
  // Overlays remember the route they were opened on: navigating anywhere else
  // (link click, back/forward) implicitly closes them — derived, so no
  // close-on-route-change effect is needed.
  const [overlays, setOverlays] = useState<{
    path: string;
    open: Record<OverlayKey, boolean>;
  }>({ path: pathname, open: { mobile: false, search: false, cart: false } });
  const [query, setQuery] = useState("");

  const { items, totalItems, totalPrice, updateQuantity, removeItem } =
    useCart();
  const customerSession = useCustomerSession();

  const onCurrentRoute = overlays.path === pathname;
  const isOpen = (key: OverlayKey) => onCurrentRoute && overlays.open[key];

  function setOpen(key: OverlayKey, value: boolean) {
    setOverlays((prev) => ({
      path: pathname,
      open: { ...prev.open, [key]: value },
    }));
  }

  function toggleOverlay(key: OverlayKey) {
    setOverlays((prev) => ({
      path: pathname,
      open: { ...prev.open, [key]: !(prev.path === pathname && prev.open[key]) },
    }));
  }

  function closeAll() {
    setOverlays((prev) => ({
      path: prev.path,
      open: { mobile: false, search: false, cart: false },
    }));
  }

  // Escape closes whichever overlay is open (menu / search / cart drawer).
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key !== "Escape") {
        return;
      }
      setOverlays((prev) => ({
        path: prev.path,
        open: { mobile: false, search: false, cart: false },
      }));
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  const isNavLinkActive = (href: string) =>
    pathname === href || pathname.startsWith(`${href}/`);

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8 md:h-20">
          <Link
            href="/"
            onClick={closeAll}
            aria-label="Luce Bianca home"
            className="flex items-center gap-2 py-1 pr-3 sm:pr-4"
          >
            <Image
              src="/logo.png"
              alt=""
              width={126}
              height={84}
              priority
              className="h-12 w-auto md:h-14"
            />
            <span className="font-serif text-lg font-medium text-neutral-900 hidden sm:block">
              Luce Bianca
            </span>
          </Link>

          <nav
            aria-label="Main"
            className="hidden items-center gap-8 md:flex"
          >
            {NAV_LINKS.map((link) => {
              const active = isNavLinkActive(link.href);
              return (
                <Link
                  key={link.href}
                  href={link.href}
                  aria-current={active ? "page" : undefined}
                  className={`border-b pb-1 text-sm transition-colors ${
                    active
                      ? "border-neutral-900 font-medium text-neutral-900"
                      : "border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900"
                  }`}
                >
                  {link.label}
                </Link>
              );
            })}
          </nav>

          <div className="flex items-center gap-1 sm:gap-2">
            <button
              type="button"
              aria-label="Search"
              aria-expanded={isOpen("search")}
              onClick={() => { toggleOverlay("search"); setOpen("cart", false); }}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
            >
              <SearchIcon />
            </button>
            {customerSession !== null ? (
              <Link
                href="/account"
                aria-label="My account"
                onClick={() => { setOpen("search", false); setOpen("cart", false); }}
                className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
              >
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-neutral-900 text-[10px] font-medium uppercase leading-none text-white">
                  {customerSession.user.name.charAt(0)}
                </span>
              </Link>
            ) : (
              <Link
                href="/login"
                aria-label="Sign in"
                onClick={() => { setOpen("search", false); setOpen("cart", false); }}
                className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
              >
                <UserIcon />
              </Link>
            )}
            <button
              type="button"
              aria-label="Open cart"
              aria-expanded={isOpen("cart")}
              onClick={() => { toggleOverlay("cart"); setOpen("search", false); }}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
            >
              <span className="relative inline-block">
                <CartIcon />
                {totalItems > 0 ? (
                  <span className="absolute -right-2 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-neutral-900 px-1 text-[10px] font-medium leading-none text-white">
                    {totalItems > 99 ? "99+" : totalItems}
                  </span>
                ) : null}
              </span>
            </button>
            <button
              type="button"
              aria-label={isOpen("mobile") ? "Close menu" : "Open menu"}
              aria-expanded={isOpen("mobile")}
              onClick={() => { toggleOverlay("mobile"); setOpen("search", false); }}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900 md:hidden"
            >
              {isOpen("mobile") ? <CloseIcon /> : <MenuIcon />}
            </button>
          </div>
        </div>

        {isOpen("search") ? (
          <div className="border-t border-neutral-200 bg-white">
            <div className="mx-auto max-w-3xl px-4 py-4 sm:px-6">
              <div className="flex items-center gap-3 border-b-2 border-neutral-900 pb-2">
                <SearchIcon className="h-5 w-5 text-neutral-400" />
                <input
                  autoFocus
                  type="text"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="Search Luce Bianca…"
                  aria-label="Search Luce Bianca"
                  className="w-full bg-transparent text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                />
                <button
                  type="button"
                  aria-label="Close search"
                  onClick={() => setOpen("search", false)}
                  className="text-neutral-500 transition-colors hover:text-neutral-900"
                >
                  <CloseIcon />
                </button>
              </div>
              <p className="mt-2 text-xs text-neutral-500">
                Search is coming soon.
              </p>
            </div>
          </div>
        ) : null}
      </header>

      {isOpen("mobile") ? (
        <div className="fixed inset-0 z-[60] md:hidden">
          <button
            type="button"
            aria-label="Close menu"
            onClick={() => setOpen("mobile", false)}
            className="absolute inset-0 bg-black/40"
          />
          <nav
            aria-label="Mobile"
            className="absolute bottom-0 right-0 top-0 flex w-72 max-w-[80%] flex-col bg-white p-6 shadow-xl"
          >
            <div className="flex items-center justify-between">
              <span className="font-serif text-lg">Menu</span>
              <button
                type="button"
                aria-label="Close menu"
                onClick={() => setOpen("mobile", false)}
                className="rounded-[4px] p-1 text-neutral-700"
              >
                <CloseIcon />
              </button>
            </div>
            <div className="mt-8 flex flex-col gap-6">
              {NAV_LINKS.map((link) => {
                const active = isNavLinkActive(link.href);
                return (
                  <Link
                    key={link.href}
                    href={link.href}
                    onClick={closeAll}
                    aria-current={active ? "page" : undefined}
                    className={`font-serif text-2xl ${
                      active
                        ? "text-neutral-900 underline underline-offset-8"
                        : "text-neutral-900"
                    }`}
                  >
                    {link.label}
                  </Link>
                );
              })}
              <button
                type="button"
                onClick={() => {
                  setOpen("mobile", false);
                  setOpen("search", true);
                }}
                className="flex items-center gap-3 text-base text-neutral-700"
              >
                <SearchIcon className="h-5 w-5" />
                Search
              </button>
            </div>
            <p className="mt-auto text-xs uppercase tracking-[0.3em] text-neutral-400">
              Casual Luxury
            </p>
          </nav>
        </div>
      ) : null}

      {isOpen("cart") ? (
        <div className="fixed inset-0 z-[55]">
          <button
            type="button"
            aria-label="Close cart"
            onClick={() => setOpen("cart", false)}
            className="absolute inset-0 bg-black/40"
          />
          <div className="absolute right-0 top-0 flex max-h-[85vh] w-full max-w-sm flex-col border-l border-neutral-200 bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-neutral-200 px-6 py-5">
              <h2 className="font-serif text-xl">Your Cart</h2>
              <button
                type="button"
                aria-label="Close cart"
                onClick={() => setOpen("cart", false)}
                className="rounded-[4px] p-1 text-neutral-700"
              >
                <CloseIcon />
              </button>
            </div>

            {items.length === 0 ? (
              <div className="p-6">
                <p className="mt-4 text-sm text-neutral-600">
                  Your cart is empty.
                </p>
                <p className="mt-2 text-xs text-neutral-400">
                  Add something you love from the shop.
                </p>
                <Link
                  href="/shop"
                  onClick={() => setOpen("cart", false)}
                  className="mt-6 inline-block text-sm font-medium text-neutral-900 underline underline-offset-4"
                >
                  Continue shopping
                </Link>
              </div>
            ) : (
              <>
                <div className="flex-1 overflow-y-auto px-6 py-4">
                  <ul className="divide-y divide-neutral-100">
                    {items.map((item) => (
                      <li key={item.variant_id} className="flex gap-4 py-4">
                        <Link
                          href={`/product/${item.product_slug}`}
                          onClick={() => setOpen("cart", false)}
                          className="shrink-0"
                        >
                          {item.image_url ? (
                            <Image
                              src={item.image_url}
                              alt={item.product_name}
                              width={72}
                              height={72}
                              className="h-[72px] w-[72px] rounded-md border border-neutral-200 object-cover"
                            />
                          ) : (
                            <div className="flex h-[72px] w-[72px] items-center justify-center rounded-md border border-neutral-200 bg-neutral-100 font-serif text-xl text-neutral-300">
                              {item.product_name.charAt(0)}
                            </div>
                          )}
                        </Link>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-start justify-between gap-2">
                            <Link
                              href={`/product/${item.product_slug}`}
                              onClick={() => setOpen("cart", false)}
                              className="truncate font-serif text-sm text-neutral-900"
                            >
                              {item.product_name}
                            </Link>
                            <button
                              type="button"
                              aria-label={`Remove ${item.product_name}`}
                              onClick={() => removeItem(item.variant_id)}
                              className="p-0.5 text-neutral-400 transition-colors hover:text-neutral-900"
                            >
                              <CloseIcon />
                            </button>
                          </div>
                          <p className="mt-0.5 text-xs text-neutral-500">
                            {item.size} · {item.color}
                          </p>
                          <p className="mt-0.5 text-xs text-neutral-500">
                            {formatPrice(item.unit_price)} each
                          </p>
                          <div className="mt-2 flex items-center gap-2">
                            <button
                              type="button"
                              aria-label={`Decrease ${item.product_name} quantity`}
                              onClick={() =>
                                updateQuantity(item.variant_id, item.quantity - 1)
                              }
                              disabled={item.quantity <= 1}
                              className="flex h-7 w-7 items-center justify-center rounded border border-neutral-300 text-neutral-600 transition-colors hover:border-neutral-900 disabled:cursor-not-allowed disabled:border-neutral-200 disabled:text-neutral-300"
                            >
                              −
                            </button>
                            <span
                              className="w-6 text-center text-sm tabular-nums"
                              aria-label="Quantity"
                            >
                              {item.quantity}
                            </span>
                            <button
                              type="button"
                              aria-label={`Increase ${item.product_name} quantity`}
                              onClick={() =>
                                updateQuantity(item.variant_id, item.quantity + 1)
                              }
                              disabled={item.quantity >= item.stock_quantity}
                              className="flex h-7 w-7 items-center justify-center rounded border border-neutral-300 text-neutral-600 transition-colors hover:border-neutral-900 disabled:cursor-not-allowed disabled:border-neutral-200 disabled:text-neutral-300"
                            >
                              +
                            </button>
                          </div>
                        </div>
                        <p className="text-sm font-medium tabular-nums">
                          {formatPrice(item.unit_price * item.quantity)}
                        </p>
                      </li>
                    ))}
                  </ul>
                </div>
                <div className="border-t border-neutral-200 px-6 py-5">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-neutral-600">Total</span>
                    <span className="font-serif text-lg tabular-nums">
                      {formatPrice(totalPrice)}
                    </span>
                  </div>
                  <Link
                    href="/checkout"
                    onClick={() => setOpen("cart", false)}
                    className="mt-4 block rounded-lg bg-neutral-900 px-4 py-3 text-center text-sm font-medium text-white transition-colors hover:bg-neutral-700"
                  >
                    Checkout
                  </Link>
                  <Link
                    href="/cart"
                    onClick={() => setOpen("cart", false)}
                    className="mt-2 block text-center text-sm text-neutral-600 underline underline-offset-4 transition-colors hover:text-neutral-900"
                  >
                    View full cart
                  </Link>
                </div>
              </>
            )}
          </div>
        </div>
      ) : null}
    </>
  );
}

function SearchIcon({ className = "h-5 w-5" }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <circle cx="11" cy="11" r="7" />
      <path d="m21 21-4.3-4.3" strokeLinecap="round" />
    </svg>
  );
}

function CartIcon({ className = "h-5 w-5" }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <path d="M6.5 7h11l1.2 12.5a1.5 1.5 0 0 1-1.5 1.5H6.8a1.5 1.5 0 0 1-1.5-1.5L6.5 7Z" />
      <path d="M9 9V6a3 3 0 0 1 6 0v3" />
    </svg>
  );
}

function UserIcon({ className = "h-5 w-5" }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      aria-hidden="true"
    >
      <circle cx="12" cy="8" r="4" />
      <path d="M4.5 20a7.5 7.5 0 0 1 15 0" strokeLinecap="round" />
    </svg>
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