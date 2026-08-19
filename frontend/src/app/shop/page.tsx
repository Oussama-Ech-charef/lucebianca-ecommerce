import Link from "next/link";
import type { Metadata } from "next";

import { ApiError } from "@/lib/api";
import { OG_IMAGE, SITE_URL } from "@/lib/site";
import {
  buildProductQuery,
  fetchCategories,
  fetchProducts,
} from "@/lib/storefront";
import type { Paginated, ShopProduct } from "@/lib/types";
import FilterPanel, { type CurrentFilters } from "./filter-panel";
import ProductCard from "./product-card";

export const metadata: Metadata = {
  title: "Shop",
  description:
    "Browse the full Luce Bianca collection — classic fits and original artwork. Filter by category, size, color, and price.",
  openGraph: {
    type: "website",
    siteName: "Luce Bianca",
    title: "Shop",
    description:
      "Browse the full Luce Bianca collection — classic fits and original artwork. Filter by category, size, color, and price.",
    url: `${SITE_URL}/shop`,
    images: [OG_IMAGE],
  },
  twitter: {
    card: "summary_large_image",
    title: "Shop",
    description:
      "Browse the full Luce Bianca collection — classic fits and original artwork. Filter by category, size, color, and price.",
    images: [OG_IMAGE],
  },
};

export default async function ShopPage({ searchParams }: PageProps<"/shop">) {
  const sp = await searchParams;
  const str = (key: string): string | undefined => {
    const value = sp[key];
    return typeof value === "string" ? value : undefined;
  };

  const filters: CurrentFilters = {
    category: str("category"),
    size: str("size"),
    color: str("color"),
    min_price: str("min_price"),
    max_price: str("max_price"),
  };

  const [categories, productsResult] = await Promise.all([
    fetchCategories().catch(() => ({ data: [] })),
    fetchProducts({ ...filters, page: str("page") }).then(
      (products): ProductsResult => ({ ok: true, products }),
      (error: unknown): ProductsResult => ({
        ok: false,
        message:
          error instanceof ApiError
            ? error.message
            : "Unable to load products.",
      }),
    ),
  ]);

  if (!productsResult.ok) {
    return (
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
        <ShopHeading resultCount={null} />
        <Message title="Something went wrong" body={productsResult.message} />
      </main>
    );
  }

  const { page, pages, total } = productsResult.products.meta;
  const filteredCount = `${total} ${total === 1 ? "product" : "products"}`;

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
      <ShopHeading resultCount={filteredCount} />

      <div className="mt-6">
        <FilterPanel
          key={JSON.stringify(filters)}
          categories={categories.data}
          current={filters}
        />
      </div>

      {productsResult.products.data.length === 0 ? (
        <Message
          title="No products found"
          body="Nothing matches those filters. Try clearing them or picking different options."
        />
      ) : (
        <>
          <div className="mt-8 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
            {productsResult.products.data.map((product: ShopProduct) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
          <Pagination page={page} pages={pages} filters={filters} />
        </>
      )}
    </main>
  );
}

function ShopHeading({ resultCount }: { resultCount: string | null }) {
  return (
    <div>
      <h1 className="font-serif text-4xl tracking-tight">Shop</h1>
      {resultCount ? (
        <p className="mt-2 text-sm text-neutral-500">{resultCount}</p>
      ) : null}
    </div>
  );
}

function Message({ title, body }: { title: string; body: string }) {
  return (
    <div className="mt-10 flex flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 py-20 text-center">
      <p className="font-serif text-2xl">{title}</p>
      <p className="mt-2 max-w-sm text-sm text-neutral-500">{body}</p>
    </div>
  );
}

type ProductsResult =
  | { ok: true; products: Paginated<ShopProduct> }
  | { ok: false; message: string };

function Pagination({
  page,
  pages,
  filters,
}: {
  page: number;
  pages: number;
  filters: CurrentFilters;
}) {
  if (pages <= 1) {
    return null;
  }

  const hrefFor = (target: number) =>
    `/shop${buildProductQuery({ ...filters, page: String(target) })}`;

  return (
    <nav
      className="mt-12 flex items-center justify-center gap-2"
      aria-label="Pagination"
    >
      {page > 1 ? (
        <Link
          href={hrefFor(page - 1)}
          className="rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition-colors hover:bg-neutral-100"
        >
          Previous
        </Link>
      ) : null}
      {Array.from({ length: pages }, (_, index) => index + 1).map((number) => (
        <Link
          key={number}
          href={hrefFor(number)}
          aria-current={number === page ? "page" : undefined}
          className={`rounded-md px-3 py-2 text-sm ${
            number === page
              ? "bg-neutral-900 text-white"
              : "border border-neutral-300 text-neutral-700 transition-colors hover:bg-neutral-100"
          }`}
        >
          {number}
        </Link>
      ))}
      {page < pages ? (
        <Link
          href={hrefFor(page + 1)}
          className="rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition-colors hover:bg-neutral-100"
        >
          Next
        </Link>
      ) : null}
    </nav>
  );
}