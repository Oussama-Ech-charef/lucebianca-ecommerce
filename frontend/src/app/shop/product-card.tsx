import Image from "next/image";
import Link from "next/link";

import { formatPrice } from "@/lib/storefront";
import type { ShopProduct } from "@/lib/types";

export default function ProductCard({ product }: { product: ShopProduct }) {
  const mainImage =
    product.images.find((image) => image.is_main === 1) ?? product.images[0];

  return (
    <Link href={`/product/${product.slug}`} className="group block">
      <div className="aspect-square w-full overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
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
      </div>
      <h2 className="mt-3 font-serif text-lg leading-snug">{product.name}</h2>
      <p className="mt-1 text-sm text-neutral-600">
        {formatPrice(product.base_price)}
      </p>
    </Link>
  );
}