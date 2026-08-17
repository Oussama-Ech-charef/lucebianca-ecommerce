"use client";

import Image from "next/image";
import { Navigation, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

import type { ProductImage } from "@/lib/types";

import "swiper/css";
import "swiper/css/navigation";
import "swiper/css/pagination";

/**
 * Product image gallery (spec: Swiper.js). Falls back to a single image or
 * a placeholder block when a product has no photos yet.
 */
export default function Gallery({ images }: { images: ProductImage[] }) {
  if (images.length === 0) {
    return (
      <div className="flex aspect-square w-full items-center justify-center rounded-xl border border-neutral-200 bg-neutral-100 font-serif text-4xl text-neutral-300">
        Luce Bianca
      </div>
    );
  }

  if (images.length === 1) {
    return (
      <div className="aspect-square w-full overflow-hidden rounded-xl bg-neutral-100">
        <Image
          src={images[0].image_url}
          alt="Product photo"
          width={1200}
          height={1200}
          sizes="(min-width: 1024px) 50vw, 100vw"
          className="h-full w-full object-cover"
        />
      </div>
    );
  }

  return (
    <Swiper
      modules={[Navigation, Pagination]}
      navigation
      pagination={{ clickable: true }}
      className="aspect-square w-full overflow-hidden rounded-xl"
      loop
    >
      {images.map((image) => (
        <SwiperSlide key={image.id}>
          <div className="aspect-square w-full bg-neutral-100">
            <Image
              src={image.image_url}
              alt="Product photo"
              width={1200}
              height={1200}
              sizes="(min-width: 1024px) 50vw, 100vw"
              className="h-full w-full object-cover"
            />
          </div>
        </SwiperSlide>
      ))}
    </Swiper>
  );
}