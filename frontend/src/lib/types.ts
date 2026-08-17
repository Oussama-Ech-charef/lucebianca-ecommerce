/**
 * Types mirroring the PHP API JSON payloads (see api/src/Models and
 * api/src/Controllers). Keep in sync with the API shapes.
 */

export type Category = {
  id: number;
  name: string;
  slug: string;
  created_at: string;
};

export type Product = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  base_price: string;
  category_id: number | null;
  is_active: number;
  meta_title: string | null;
  meta_description: string | null;
  created_at: string;
  updated_at: string;
};

export type ProductImage = {
  id: number;
  product_id: number;
  image_url: string;
  is_main: number;
  sort_order: number;
  created_at?: string;
};

/** A size/color combination of a product. price null = falls back to base_price. */
export type Variant = {
  id: number;
  product_id: number;
  size: string;
  color: string;
  sku: string;
  price: string | null;
  stock_quantity: number;
};

/** An item in GET /api/products — card-level data including its images. */
export type ShopProduct = Product & {
  images: ProductImage[];
};

/** Full payload of GET /api/products/{slug}. */
export type ProductDetail = Product & {
  variants: Variant[];
  images: ProductImage[];
};

export type Paginated<T> = {
  data: T[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    pages: number;
  };
};

export type CategoriesResponse = {
  data: Category[];
};