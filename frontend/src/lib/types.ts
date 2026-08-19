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

/** A line of an order — GET /api/orders/{id} and POST /api/orders. */
export type OrderLineItem = {
  id: number;
  product_variant_id: number;
  quantity: number;
  price_at_purchase: string;
  size: string;
  color: string;
  sku: string;
  product_id: number;
  product_name: string;
  product_slug: string;
  image_url: string | null;
};

/** Full payload of GET /api/orders/{id} and POST /api/orders. */
export type OrderDetail = {
  id: number;
  user_id: number | null;
  status: string;
  total_amount: string;
  shipping_address: string;
  customer_name: string;
  phone: string;
  payment_method: string;
  payment_status: string;
  created_at: string;
  items: OrderLineItem[];
};

/**
 * A per-line verdict from POST /api/cart. `available` is false with a
 * `reason` (variant_not_found | product_unavailable | insufficient_stock)
 * when the line can't be fulfilled as requested.
 */
export type CartValidationLine = {
  variant_id: number;
  product_id?: number;
  product_name?: string;
  slug?: string;
  image_url?: string | null;
  size?: string;
  color?: string;
  sku?: string;
  unit_price?: string;
  stock_quantity?: number;
  requested_quantity: number;
  available: boolean;
  /** Max quantity orderable — set when insufficient_stock. */
  available_quantity?: number;
  reason?: string;
  /** True when the authoritative price differs from what the client sent. */
  price_changed?: boolean;
};

export type CartValidationResponse = {
  data: {
    lines: CartValidationLine[];
    valid: boolean;
  };
};