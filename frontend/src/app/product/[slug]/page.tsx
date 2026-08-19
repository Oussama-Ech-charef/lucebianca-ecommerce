import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";

import { ApiError } from "@/lib/api";
import { OG_IMAGE, SITE_URL } from "@/lib/site";
import { fetchProductDetail, formatPrice } from "@/lib/storefront";
import type { ProductDetail } from "@/lib/types";
import Gallery from "./gallery";
import VariantSelector from "./variant-selector";

type PageProps = { params: Promise<{ slug: string }> };

// Shared by generateMetadata and the page so the API is hit once per request.
const getProduct = cache(async (slug: string): Promise<ProductDetail | null> => {
  try {
    const response = await fetchProductDetail(slug);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }
    throw error;
  }
});

function productMainImage(product: ProductDetail): string | null {
  return (
    product.images.find((image) => image.is_main === 1)?.image_url ??
    product.images[0]?.image_url ??
    null
  );
}

function productLowPrice(product: ProductDetail): number {
  const prices = product.variants
    .map((variant) => Number(variant.price ?? product.base_price))
    .filter((value) => Number.isFinite(value));
  return prices.length > 0 ? Math.min(...prices) : Number(product.base_price);
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const product = await getProduct(slug);

  if (!product) {
    return { title: "Product not found" };
  }

  const description =
    product.meta_description ?? product.description ?? undefined;
  const mainImage = productMainImage(product);
  const priceLine = `From ${formatPrice(productLowPrice(product))}`;
  const ogDescription = description
    ? `${description} — ${priceLine}`
    : priceLine;

  return {
    title: product.meta_title
      ? { absolute: product.meta_title }
      : product.name,
    description,
    openGraph: {
      type: "website",
      siteName: "Luce Bianca",
      title: product.meta_title ?? product.name,
      description: ogDescription,
      url: `${SITE_URL}/product/${product.slug}`,
      // Product image when present; otherwise the root logo fallback
      // (OG_IMAGE) — a child openGraph does not inherit the layout's image.
      images: mainImage
        ? [{ url: mainImage, alt: product.name }]
        : [OG_IMAGE],
    },
    twitter: {
      card: "summary_large_image",
      title: product.meta_title ?? product.name,
      description: ogDescription,
      images: mainImage ? [mainImage] : [OG_IMAGE],
    },
  };
}

export default async function ProductPage({ params }: PageProps) {
  const { slug } = await params;
  const product = await getProduct(slug);

  if (!product) {
    notFound();
  }

  const prices = product.variants
    .map((variant) => Number(variant.price ?? product.base_price))
    .filter((value) => Number.isFinite(value));
  const lowPrice = prices.length > 0 ? Math.min(...prices) : Number(product.base_price);
  const highPrice = prices.length > 0 ? Math.max(...prices) : Number(product.base_price);
  const inStock =
    product.variants.length === 0 ||
    product.variants.some((variant) => variant.stock_quantity > 0);

  const mainImage = productMainImage(product);

  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Product",
    name: product.name,
    image: product.images.map((image) => image.image_url),
    description: product.description ?? undefined,
    sku: product.variants[0]?.sku,
    offers: {
      "@type": "AggregateOffer",
      priceCurrency: "MAD",
      lowPrice: lowPrice.toFixed(2),
      highPrice: highPrice.toFixed(2),
      offerCount: product.variants.length,
      availability: inStock
        ? "https://schema.org/InStock"
        : "https://schema.org/OutOfStock",
    },
  };

  const breadcrumbLd = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      {
        "@type": "ListItem",
        position: 1,
        name: "Home",
        item: `${SITE_URL}/`,
      },
      {
        "@type": "ListItem",
        position: 2,
        name: "Shop",
        item: `${SITE_URL}/shop`,
      },
      {
        "@type": "ListItem",
        position: 3,
        name: product.name,
        item: `${SITE_URL}/product/${product.slug}`,
      },
    ],
  };

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(jsonLd).replace(/</g, "\\u003c"),
        }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(breadcrumbLd).replace(/</g, "\\u003c"),
        }}
      />

      <Link
        href="/shop"
        className="text-sm text-neutral-500 transition-colors hover:text-neutral-900"
      >
        ← Back to shop
      </Link>

      <div className="mt-6 grid gap-8 lg:grid-cols-2 lg:gap-12">
        <Gallery images={product.images} />

        <div className="flex flex-col">
          <h1 className="font-serif text-3xl tracking-tight lg:text-4xl">
            {product.name}
          </h1>

          <div className="mt-6">
            <VariantSelector
              variants={product.variants}
              basePrice={product.base_price}
              productName={product.name}
              productSlug={product.slug}
              mainImage={mainImage}
            />
          </div>

          {product.description ? (
            <div className="mt-8 border-t border-neutral-200 pt-6">
              <h2 className="font-serif text-lg">About this product</h2>
              <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-neutral-600">
                {product.description}
              </p>
            </div>
          ) : null}

          {product.variants.length > 0 ? (
            <p className="mt-8 text-xs text-neutral-400">
              From {formatPrice(lowPrice)} · {product.variants.length}{" "}
              {product.variants.length === 1 ? "option" : "options"}
            </p>
          ) : null}
        </div>
      </div>
    </main>
  );
}