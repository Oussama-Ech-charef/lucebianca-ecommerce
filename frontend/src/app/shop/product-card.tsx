import Image from "next/image";
import Link from "next/link";

import { formatPrice } from "@/lib/storefront";
import type { ShopProduct } from "@/lib/types";

export default function ProductCard({ product }: { product: ShopProduct }) {
  const mainImage =
    product.images.find((image) => image.is_main === 1) ?? product.images[0];

  const hasSale = product.sale_price !== null && product.sale_price !== "";

  return (
    <Link href={`/product/${product.slug}`} className="group block">
      <div className="aspect-square w-full overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100 relative">
        {mainImage ? (
          <Image
            src={mainImage.image_url}
            alt={product.name}
            width={800}
            height={800}
            sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 90vw"
            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center font-serif text-4xl text-neutral-300">
            {product.name.charAt(0)}
          </div>
        )}
        {hasSale && (
          <span className="absolute top-2 left-2 z-10 bg-red-600 text-white text-xs font-medium px-2 py-1 rounded">
            Sale
          </span>
        )}
      </div>
      <h2 className="mt-3 font-serif text-lg leading-snug">{product.name}</h2>
      <p className="mt-1 text-sm text-neutral-600 flex items-center gap-2">
        {hasSale && (
          <>
            <span className="font-medium text-red-600">{formatPrice(product.sale_price!)}</span>
            <span className="text-neutral-400 line-through text-sm">{formatPrice(product.base_price)}</span>
          </>
        )}
        {!product.sale_price && <span className="font-medium">{formatPrice(product.base_price)}</span>}
      </p>
    </Link>
  );
}