"use client";

import { useEffect } from "react";

import { useCart } from "@/lib/cart-context";

/** Clears the cart once the confirmation page mounts (order already placed). */
export default function ClearCart() {
  const { clear } = useCart();

  useEffect(() => {
    clear();
  }, [clear]);

  return null;
}