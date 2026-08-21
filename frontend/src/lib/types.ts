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

/** Order status — mirrors the orders.status ENUM (spec section 4). */
export type OrderStatus =
  | "pending"
  | "processing"
  | "shipped"
  | "delivered"
  | "cancelled";

/** Payment status — mirrors the orders.payment_status ENUM (spec section 4). */
export type PaymentStatus = "pending" | "paid" | "failed";

/**
 * A row in GET /api/admin/orders — the light list payload (summary fields +
 * item count, deliberately no line items; a detail view fetches
 * GET /api/orders/{id}).
 */
export type AdminOrder = {
  id: number;
  customer_name: string;
  phone: string;
  total_amount: string;
  status: OrderStatus;
  payment_method: string;
  payment_status: PaymentStatus;
  created_at: string;
  item_count: number;
};

/** Payload of POST /api/admin/auth/login. */
export type AdminAuthPayload = {
  token: string;
  refresh_token: string;
  admin: {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
  };
};

/**
 * A customer profile — GET /api/account (never includes password data).
 * `email_verified` (phase 16) is informational — login and checkout are not
 * blocked on it; the /account page surfaces a resend banner when false.
 */
export type CustomerUser = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  email_verified: boolean;
  created_at: string;
};

/**
 * Payload of POST /api/auth/register, POST /api/auth/login and
 * POST /api/auth/google. Persisted under `lucebianca:customer:session:v1` —
 * a deliberately separate store from the admin session and the cart.
 */
export type CustomerAuthPayload = {
  token: string;
  refresh_token: string;
  user: CustomerUser;
};

/**
 * A row in GET /api/account/orders — the light list payload (same summary
 * shape as AdminOrder but scoped to the authenticated customer's own orders).
 */
export type CustomerOrder = {
  id: number;
  customer_name: string;
  phone: string;
  total_amount: string;
  status: OrderStatus;
  payment_method: string;
  payment_status: PaymentStatus;
  created_at: string;
  item_count: number;
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
  status: OrderStatus;
  total_amount: string;
  shipping_address: string;
  customer_name: string;
  phone: string;
  payment_method: string;
  payment_status: PaymentStatus;
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