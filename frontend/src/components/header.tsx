"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";

import { useCart } from "@/lib/cart-context";
import { useCustomerSession } from "@/lib/customer-auth";
import { formatPrice } from "@/lib/storefront";

// Only /shop exists as a route. About/Contact links are deliberately omitted
// until their pages are built (phase roadmap), rather than linking to 404s.
const NAV_LINKS = [{ label: "Shop", href: "/shop" }];

export default function Header() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [cartOpen, setCartOpen] = useState(false);
  const [query, setQuery] = useState("");

  const { items, totalItems, totalPrice, updateQuantity, removeItem } =
    useCart();
  const customerSession = useCustomerSession();

  function closeAll() {
    setMobileOpen(false);
    setSearchOpen(false);
    setCartOpen(false);
  }

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8 md:h-20">
          <Link
            href="/"
            onClick={closeAll}
            aria-label="Luce Bianca home"
            className="flex items-center py-1 pr-3 sm:pr-4"
          >
            <Image
              src="/logo.png"
              alt="Luce Bianca"
              width={126}
              height={84}
              priority
              className="h-12 w-auto md:h-14"
            />
          </Link>

          <nav
            aria-label="Main"
            className="hidden items-center gap-8 md:flex"
          >
            {NAV_LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="text-sm text-neutral-600 transition-colors hover:text-neutral-900"
              >
                {link.label}
              </Link>
            ))}
          </nav>

          <div className="flex items-center gap-1 sm:gap-2">
            <button
              type="button"
              aria-label="Search"
              aria-expanded={searchOpen}
              onClick={() => {
                setSearchOpen((open) => !open);
                setCartOpen(false);
              }}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
            >
              <SearchIcon />
            </button>
            {customerSession !== null ? (
              <Link
                href="/account"
                aria-label="My account"
                onClick={() => {
                  setSearchOpen(false);
                  setCartOpen(false);
                }}
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
                onClick={() => {
                  setSearchOpen(false);
                  setCartOpen(false);
                }}
                className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900"
              >
                <UserIcon />
              </Link>
            )}
            <button
              type="button"
              aria-label="Open cart"
              aria-expanded={cartOpen}
              onClick={() => {
                setCartOpen((open) => !open);
                setSearchOpen(false);
              }}
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
              aria-label={mobileOpen ? "Close menu" : "Open menu"}
              aria-expanded={mobileOpen}
              onClick={() => {
                setMobileOpen((open) => !open);
                setSearchOpen(false);
              }}
              className="flex h-10 w-10 items-center justify-center rounded-[4px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-neutral-900 md:hidden"
            >
              {mobileOpen ? <CloseIcon /> : <MenuIcon />}
            </button>
          </div>
        </div>

        {searchOpen ? (
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
                  onClick={() => setSearchOpen(false)}
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

      {mobileOpen ? (
        <div className="fixed inset-0 z-[60] md:hidden">
          <button
            type="button"
            aria-label="Close menu"
            onClick={() => setMobileOpen(false)}
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
                onClick={() => setMobileOpen(false)}
                className="rounded-[4px] p-1 text-neutral-700"
              >
                <CloseIcon />
              </button>
            </div>
            <div className="mt-8 flex flex-col gap-6">
              {NAV_LINKS.map((link) => (
                <Link
                  key={link.href}
                  href={link.href}
                  onClick={closeAll}
                  className="font-serif text-2xl text-neutral-900"
                >
                  {link.label}
                </Link>
              ))}
              <button
                type="button"
                onClick={() => {
                  setMobileOpen(false);
                  setSearchOpen(true);
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

      {cartOpen ? (
        <div className="fixed inset-0 z-[55]">
          <button
            type="button"
            aria-label="Close cart"
            onClick={() => setCartOpen(false)}
            className="absolute inset-0 bg-black/40"
          />
          <div className="absolute right-0 top-0 flex max-h-[85vh] w-full max-w-sm flex-col border-l border-neutral-200 bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-neutral-200 px-6 py-5">
              <h2 className="font-serif text-xl">Your Cart</h2>
              <button
                type="button"
                aria-label="Close cart"
                onClick={() => setCartOpen(false)}
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
                  onClick={() => setCartOpen(false)}
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
                          onClick={() => setCartOpen(false)}
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
                              onClick={() => setCartOpen(false)}
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
                    onClick={() => setCartOpen(false)}
                    className="mt-4 block rounded-lg bg-neutral-900 px-4 py-3 text-center text-sm font-medium text-white transition-colors hover:bg-neutral-700"
                  >
                    Checkout
                  </Link>
                  <Link
                    href="/cart"
                    onClick={() => setCartOpen(false)}
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