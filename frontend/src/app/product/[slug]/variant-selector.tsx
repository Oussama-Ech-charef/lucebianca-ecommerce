"use client";

import { useMemo, useState } from "react";

import { formatPrice } from "@/lib/storefront";
import type { Variant } from "@/lib/types";

/**
 * Size + color selection driven entirely by the API's variant list. The
 * "Add to cart" button is intentionally inert: cart/checkout arrive in
 * phase 6, so this phase shows the state but performs no action.
 */
export default function VariantSelector({
  variants,
  basePrice,
}: {
  variants: Variant[];
  basePrice: string;
}) {
  const sizes = useMemo(
    () => Array.from(new Set(variants.map((variant) => variant.size))),
    [variants],
  );
  const colors = useMemo(
    () => Array.from(new Set(variants.map((variant) => variant.color))),
    [variants],
  );

  const [size, setSize] = useState<string | null>(sizes[0] ?? null);
  const [color, setColor] = useState<string | null>(colors[0] ?? null);

  const colorsForSize = useMemo(
    () =>
      Array.from(
        new Set(
          variants
            .filter((variant) => variant.size === size)
            .map((variant) => variant.color),
        ),
      ),
    [variants, size],
  );

  const selected = useMemo(
    () =>
      variants.find(
        (variant) => variant.size === size && variant.color === color,
      ) ?? null,
    [variants, size, color],
  );

  const price = selected?.price ?? basePrice;
  const anyInStock = variants.some((variant) => variant.stock_quantity > 0);
  const inStock = selected ? selected.stock_quantity > 0 : anyInStock;

  function chooseSize(next: string) {
    setSize(next);
    const compatibleColors = Array.from(
      new Set(
        variants
          .filter((variant) => variant.size === next)
          .map((variant) => variant.color),
      ),
    );
    if (!compatibleColors.includes(color ?? "")) {
      setColor(compatibleColors[0] ?? null);
    }
  }

  const chip =
    "rounded-md border px-3 py-2 text-sm transition-colors focus:outline-none";
  const chipEnabled =
    "border-neutral-300 hover:border-neutral-900 cursor-pointer";
  const chipDisabled = "border-neutral-200 text-neutral-300 cursor-not-allowed";

  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">
        Price
      </p>
      <p className="mt-1 font-serif text-3xl">{formatPrice(price)}</p>

      {variants.length === 0 ? (
        <p className="mt-4 text-sm text-neutral-500">
          This product has no variants configured yet.
        </p>
      ) : (
        <>
          <div className="mt-6">
            <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">
              Size
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
              {sizes.map((value) => {
                const inStockSize = variants.some(
                  (variant) =>
                    variant.size === value && variant.stock_quantity > 0,
                );
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => chooseSize(value)}
                    disabled={!inStockSize}
                    aria-pressed={size === value}
                    className={`${chip} ${
                      size === value
                        ? "border-neutral-900 bg-neutral-900 text-white"
                        : inStockSize
                          ? chipEnabled
                          : chipDisabled
                    }`}
                  >
                    {value}
                  </button>
                );
              })}
            </div>
          </div>

          <div className="mt-4">
            <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">
              Color
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
              {colorsForSize.map((value) => {
                const variant = variants.find(
                  (item) => item.size === size && item.color === value,
                );
                const inStockColor = variant
                  ? variant.stock_quantity > 0
                  : false;
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setColor(value)}
                    disabled={!inStockColor}
                    aria-pressed={color === value}
                    className={`${chip} ${
                      color === value
                        ? "border-neutral-900 bg-neutral-900 text-white"
                        : inStockColor
                          ? chipEnabled
                          : chipDisabled
                    }`}
                  >
                    {value}
                  </button>
                );
              })}
            </div>
          </div>

          <p
            className={`mt-4 text-sm ${
              inStock ? "text-neutral-600" : "text-red-700"
            }`}
          >
            {selected
              ? inStock
                ? `In stock — ${selected.stock_quantity} available`
                : "Out of stock"
              : inStock
                ? "In stock"
                : "Out of stock"}
          </p>
        </>
      )}

      <button
        type="button"
        disabled={!inStock || variants.length === 0}
        title="Cart and checkout arrive in phase 6"
        className="mt-6 w-full rounded-lg bg-neutral-900 px-6 py-3 font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:bg-neutral-300"
      >
        Add to Cart
      </button>
      <p className="mt-3 text-center text-xs text-neutral-400">
        Cart and checkout arrive in a later phase.
      </p>
    </div>
  );
}