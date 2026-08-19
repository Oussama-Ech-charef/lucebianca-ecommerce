"use client";

import Image from "next/image";
import Link from "next/link";

import { useCart } from "@/lib/cart-context";
import { formatPrice } from "@/lib/storefront";

const stepperClass =
  "flex h-9 w-9 items-center justify-center rounded border border-neutral-300 text-lg text-neutral-600 transition-colors hover:border-neutral-900 disabled:cursor-not-allowed disabled:border-neutral-200 disabled:text-neutral-300";

export default function CartView() {
  const { items, totalItems, totalPrice, updateQuantity, removeItem } =
    useCart();

  if (items.length === 0) {
    return (
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-16 text-center">
        <h1 className="font-serif text-3xl">Your cart is empty</h1>
        <p className="mt-3 text-sm text-neutral-500">
          Explore the collection and find something you love.
        </p>
        <Link
          href="/shop"
          className="mt-8 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700"
        >
          Continue shopping
        </Link>
      </main>
    );
  }

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
      <h1 className="font-serif text-3xl">Your Cart</h1>
      <p className="mt-2 text-sm text-neutral-500">
        {totalItems} {totalItems === 1 ? "item" : "items"}
      </p>

      <div className="mt-8 grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
        <ul className="divide-y divide-neutral-200 border-y border-neutral-200">
          {items.map((item) => (
            <li key={item.variant_id} className="flex gap-4 py-6 sm:gap-6">
              <Link
                href={`/product/${item.product_slug}`}
                className="shrink-0"
              >
                {item.image_url ? (
                  <Image
                    src={item.image_url}
                    alt={item.product_name}
                    width={96}
                    height={96}
                    className="h-24 w-24 rounded-md border border-neutral-200 object-cover sm:h-28 sm:w-28"
                  />
                ) : (
                  <div className="flex h-24 w-24 items-center justify-center rounded-md border border-neutral-200 bg-neutral-100 font-serif text-2xl text-neutral-300 sm:h-28 sm:w-28">
                    {item.product_name.charAt(0)}
                  </div>
                )}
              </Link>

              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-3">
                  <Link
                    href={`/product/${item.product_slug}`}
                    className="font-serif text-base text-neutral-900 hover:underline"
                  >
                    {item.product_name}
                  </Link>
                  <button
                    type="button"
                    aria-label={`Remove ${item.product_name}`}
                    onClick={() => removeItem(item.variant_id)}
                    className="shrink-0 rounded-[4px] p-1 text-xs text-neutral-400 underline underline-offset-2 transition-colors hover:text-red-700"
                  >
                    Remove
                  </button>
                </div>
                <p className="mt-1 text-sm text-neutral-500">
                  {item.size} · {item.color} · SKU {item.sku}
                </p>
                <p className="mt-1 text-sm text-neutral-500">
                  {formatPrice(item.unit_price)} each
                </p>
                <div className="mt-4 flex items-center gap-3">
                  <button
                    type="button"
                    aria-label={`Decrease ${item.product_name} quantity`}
                    onClick={() => updateQuantity(item.variant_id, item.quantity - 1)}
                    disabled={item.quantity <= 1}
                    className={stepperClass}
                  >
                    −
                  </button>
                  <span
                    className="w-8 text-center text-sm tabular-nums"
                    aria-label="Quantity"
                  >
                    {item.quantity}
                  </span>
                  <button
                    type="button"
                    aria-label={`Increase ${item.product_name} quantity`}
                    onClick={() => updateQuantity(item.variant_id, item.quantity + 1)}
                    disabled={item.quantity >= item.stock_quantity}
                    className={stepperClass}
                  >
                    +
                  </button>
                  {item.quantity >= item.stock_quantity ? (
                    <span className="text-xs text-neutral-400">
                      Max {item.stock_quantity} in stock
                    </span>
                  ) : null}
                </div>
              </div>

              <p className="text-right text-base font-medium tabular-nums">
                {formatPrice(item.unit_price * item.quantity)}
              </p>
            </li>
          ))}
        </ul>

        <aside className="h-fit rounded-lg border border-neutral-200 p-6">
          <h2 className="font-serif text-xl">Order summary</h2>
          <dl className="mt-5 space-y-3 text-sm">
            <div className="flex items-center justify-between">
              <dt className="text-neutral-600">Subtotal</dt>
              <dd className="tabular-nums">{formatPrice(totalPrice)}</dd>
            </div>
            <div className="flex items-center justify-between">
              <dt className="text-neutral-600">Delivery</dt>
              <dd className="text-neutral-500">Calculated at checkout</dd>
            </div>
            <div className="flex items-center justify-between border-t border-neutral-200 pt-3">
              <dt className="font-medium text-neutral-900">Total</dt>
              <dd className="font-serif text-lg tabular-nums">
                {formatPrice(totalPrice)}
              </dd>
            </div>
          </dl>
          <Link
            href="/checkout"
            className="mt-6 block rounded-lg bg-neutral-900 px-4 py-3 text-center text-sm font-medium text-white transition-colors hover:bg-neutral-700"
          >
            Proceed to Checkout
          </Link>
          <Link
            href="/shop"
            className="mt-3 block text-center text-sm text-neutral-600 underline underline-offset-4 transition-colors hover:text-neutral-900"
          >
            Continue shopping
          </Link>
        </aside>
      </div>
    </main>
  );
}