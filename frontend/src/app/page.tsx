import Hero from "@/components/hero";
import Link from "next/link";

import { fetchNewArrivals, fetchSpecialOffers } from "@/lib/storefront";
import type { ShopProduct } from "@/lib/types";
import ProductCard from "./shop/product-card";

export default async function Home() {
  // New Arrivals: newest 8 active products (paginated endpoint would also work,
  // but dedicated helper keeps intent clear and avoids fetching extra data).
  let newArrivals: ShopProduct[] = [];
  try {
    const response = await fetchNewArrivals(8);
    newArrivals = response.data;
  } catch {
    newArrivals = [];
  }

  // Special Offers: active products with valid sale_price (< base_price, > 0)
  let specialOffers: ShopProduct[] = [];
  try {
    const response = await fetchSpecialOffers(8);
    specialOffers = response.data;
  } catch {
    specialOffers = [];
  }

  return (
    <>
      <Hero />

      {newArrivals.length > 0 && (
        <section className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
          <div className="mb-8 flex items-center justify-between">
            <h2 className="font-serif text-3xl tracking-tight">New Arrivals</h2>
            <Link
              href="/shop"
              className="text-sm font-medium text-neutral-900 underline underline-offset-4"
            >
              View all
            </Link>
          </div>
          <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
            {newArrivals.map((product: ShopProduct) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )}

      {specialOffers.length > 0 && (
        <section className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
          <div className="mb-8 flex items-center justify-between">
            <h2 className="font-serif text-3xl tracking-tight">Special Offers</h2>
            <Link
              href="/shop"
              className="text-sm font-medium text-neutral-900 underline underline-offset-4"
            >
              View all
            </Link>
          </div>
          <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
            {specialOffers.map((product: ShopProduct) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )}
    </>
  );
}
