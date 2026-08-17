"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import type { Category } from "@/lib/types";

export type CurrentFilters = {
  category?: string;
  size?: string;
  color?: string;
  min_price?: string;
  max_price?: string;
};

/**
 * Server-driven filters: every change navigates to a real /shop URL with
 * query params, so each filter result is a crawlable page (spec: SEO).
 */
export default function FilterPanel({
  categories,
  current,
}: {
  categories: Category[];
  current: CurrentFilters;
}) {
  const router = useRouter();
  const [category, setCategory] = useState(current.category ?? "");
  const [size, setSize] = useState(current.size ?? "");
  const [color, setColor] = useState(current.color ?? "");
  const [minPrice, setMinPrice] = useState(current.min_price ?? "");
  const [maxPrice, setMaxPrice] = useState(current.max_price ?? "");

  function navigate(values: Record<string, string>) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
      if (value) {
        params.set(key, value);
      }
    }
    const query = params.toString();
    router.push(`/shop${query ? `?${query}` : ""}`, { scroll: false });
  }

  function apply() {
    navigate({ category, size, color, min_price: minPrice, max_price: maxPrice });
  }

  function clear() {
    setCategory("");
    setSize("");
    setColor("");
    setMinPrice("");
    setMaxPrice("");
    navigate({});
  }

  const labelInput =
    "mt-1 block w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-neutral-500 focus:outline-none";

  return (
    <div className="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4">
      <label className="block text-xs font-medium uppercase tracking-wide text-neutral-500">
        Category
        <select
          value={category}
          onChange={(event) => setCategory(event.target.value)}
          className={labelInput}
        >
          <option value="">All categories</option>
          {categories.map((item) => (
            <option key={item.id} value={item.slug}>
              {item.name}
            </option>
          ))}
        </select>
      </label>

      <label className="block text-xs font-medium uppercase tracking-wide text-neutral-500">
        Size
        <input
          type="text"
          value={size}
          onChange={(event) => setSize(event.target.value)}
          placeholder="e.g. M"
          className={labelInput}
        />
      </label>

      <label className="block text-xs font-medium uppercase tracking-wide text-neutral-500">
        Color
        <input
          type="text"
          value={color}
          onChange={(event) => setColor(event.target.value)}
          placeholder="e.g. Black"
          className={labelInput}
        />
      </label>

      <label className="block text-xs font-medium uppercase tracking-wide text-neutral-500">
        Min price
        <input
          type="number"
          min="0"
          step="0.01"
          value={minPrice}
          onChange={(event) => setMinPrice(event.target.value)}
          placeholder="0.00"
          className={labelInput}
        />
      </label>

      <label className="block text-xs font-medium uppercase tracking-wide text-neutral-500">
        Max price
        <input
          type="number"
          min="0"
          step="0.01"
          value={maxPrice}
          onChange={(event) => setMaxPrice(event.target.value)}
          placeholder="999.00"
          className={labelInput}
        />
      </label>

      <div className="flex gap-2">
        <button
          type="button"
          onClick={apply}
          className="rounded-md bg-neutral-900 px-4 py-2 text-sm text-white transition-colors hover:bg-neutral-700"
        >
          Apply filters
        </button>
        <button
          type="button"
          onClick={clear}
          className="rounded-md border border-neutral-300 px-4 py-2 text-sm text-neutral-700 transition-colors hover:bg-neutral-100"
        >
          Clear
        </button>
      </div>
    </div>
  );
}