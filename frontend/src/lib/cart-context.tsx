"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from "react";

/**
 * Client-side cart state (phase 7).
 *
 * The cart lives entirely in React Context + localStorage — there is no
 * server session (the spec exposes only POST /api/cart validation and
 * POST /api/orders; a server cart would be redundant until checkout).
 *
 * The store is exposed via useSyncExternalStore: localStorage is read lazily
 * on the first client snapshot, so there is no hydration mismatch and no
 * "loaded" flash — the badge and panels reflect the real cart immediately
 * after hydration.
 *
 * Each item captures the variant's stock_quantity at add-time so the
 * quantity steppers are capped correctly; the API re-validates live stock
 * before an order is placed (see OrderService).
 */

export type CartItem = {
  variant_id: number;
  product_id: number;
  product_slug: string;
  product_name: string;
  image_url: string | null;
  size: string;
  color: string;
  sku: string;
  /** Unit price at add-time (variant.price ?? product.base_price). */
  unit_price: number;
  /** Stock captured at add-time — caps the quantity stepper. */
  stock_quantity: number;
  quantity: number;
};

export type CartInput = Omit<CartItem, "quantity">;

type CartContextValue = {
  items: CartItem[];
  totalItems: number;
  totalPrice: number;
  addItem: (item: CartInput, quantity?: number) => void;
  removeItem: (variantId: number) => void;
  /** Clamped to 1..stock_quantity. */
  updateQuantity: (variantId: number, quantity: number) => void;
  clear: () => void;
};

const STORAGE_KEY = "lucebianca:cart:v1";

const CartContext = createContext<CartContextValue | null>(null);

function isCartItem(value: unknown): value is CartItem {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const item = value as Record<string, unknown>;
  return (
    typeof item.variant_id === "number" &&
    typeof item.product_id === "number" &&
    typeof item.product_slug === "string" &&
    typeof item.product_name === "string" &&
    typeof item.quantity === "number" &&
    typeof item.unit_price === "number" &&
    typeof item.stock_quantity === "number"
  );
}

function readStorage(): CartItem[] {
  if (typeof window === "undefined") {
    return [];
  }
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return [];
    }
    const parsed = JSON.parse(raw) as unknown;
    return Array.isArray(parsed) ? parsed.filter(isCartItem) : [];
  } catch {
    // Corrupt storage — start with an empty cart.
    return [];
  }
}

const EMPTY_ITEMS: CartItem[] = [];

// Module-level cache so getSnapshot() is referentially stable between writes
// (useSyncExternalStore re-renders on reference change). Hydrated once from
// localStorage on the first client snapshot; survives client-side navigation.
let cachedItems: CartItem[] | null = null;

function getSnapshot(): CartItem[] {
  if (cachedItems === null) {
    cachedItems = readStorage();
  }
  return cachedItems;
}

function getServerSnapshot(): CartItem[] {
  return EMPTY_ITEMS;
}

const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function commit(next: CartItem[]) {
  cachedItems = next;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  } catch {
    // Storage blocked/full — the cart still works for this session.
  }
  for (const listener of listeners) {
    listener();
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const items = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  const addItem = useCallback((item: CartInput, quantity = 1) => {
    const current = getSnapshot();
    const existing = current.find((entry) => entry.variant_id === item.variant_id);
    if (existing) {
      commit(
        current.map((entry) =>
          entry.variant_id === item.variant_id
            ? {
                ...entry,
                quantity: Math.max(
                  1,
                  Math.min(entry.quantity + quantity, entry.stock_quantity),
                ),
              }
            : entry,
        ),
      );
    } else {
      commit([
        ...current,
        {
          ...item,
          quantity: Math.max(1, Math.min(quantity, item.stock_quantity)),
        },
      ]);
    }
  }, []);

  const removeItem = useCallback((variantId: number) => {
    commit(getSnapshot().filter((entry) => entry.variant_id !== variantId));
  }, []);

  const updateQuantity = useCallback((variantId: number, quantity: number) => {
    commit(
      getSnapshot().map((entry) =>
        entry.variant_id === variantId
          ? {
              ...entry,
              quantity: Math.max(1, Math.min(quantity, entry.stock_quantity)),
            }
          : entry,
      ),
    );
  }, []);

  const clear = useCallback(() => {
    commit([]);
  }, []);

  const value = useMemo<CartContextValue>(() => {
    const totalItems = items.reduce((sum, entry) => sum + entry.quantity, 0);
    const totalPrice = items.reduce(
      (sum, entry) => sum + entry.unit_price * entry.quantity,
      0,
    );
    return {
      items,
      totalItems,
      totalPrice,
      addItem,
      removeItem,
      updateQuantity,
      clear,
    };
  }, [items, addItem, removeItem, updateQuantity, clear]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartContextValue {
  const context = useContext(CartContext);
  if (context === null) {
    throw new Error("useCart must be used within a <CartProvider>.");
  }
  return context;
}